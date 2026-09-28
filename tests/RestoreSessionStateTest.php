<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

/**
 * Estado de la restauración por pasos: contrato del archivo de estado, sin
 * necesidad de base de datos.
 */
class RestoreSessionStateTest extends TestCase
{
    private \backup_manager $manager;

    /**
     * @var array<int, string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->manager = new \backup_manager(FS_FOLDER);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            foreach ([$file, $file . '.tmp'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $this->tempFiles = [];
    }

    private function invoke(string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($this->manager, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->manager, $args);
    }

    private function uniqueSessionId(): string
    {
        $sessionId = 'test_' . uniqid('', true);
        $this->tempFiles[] = $this->manager->restore_session_state_file($sessionId);

        return $sessionId;
    }

    /**
     * @return array<int, string>
     */
    private function expectedStateKeys(): array
    {
        return [
            'version',
            'session_id',
            'type',
            'file',
            'started_at',
            'updated_at',
            'phase',
            'temp_dir',
            'dump_path',
            'db_backup_path',
            'tables',
            'drop_index',
            'sql_offset',
            'delimiter',
            'statement_count',
            'files_done',
            'errors',
            'warnings',
        ];
    }

    public function testInitialStateCarriesNoCredentials(): void
    {
        $state = $this->invoke('restore_session_initial_state', [
            $this->uniqueSessionId(),
            'paquete_complete.zip',
            'complete',
        ]);

        $keys = array_keys($state);
        sort($keys);
        $expected = $this->expectedStateKeys();
        sort($expected);

        $this->assertSame(
            $expected,
            $keys,
            'el estado debe tener exactamente las claves permitidas: si aparece una nueva, revisá que no sea un secreto'
        );

        $json = (string) json_encode($state);
        $this->assertDoesNotMatchRegularExpression(
            '/pass|password|secret|credential/i',
            $json,
            'el estado nunca debe llevar credenciales'
        );
    }

    public function testInitialStateStartsAtPrepareAndKeepsDelimiter(): void
    {
        $state = $this->invoke('restore_session_initial_state', ['sid', 'b.sql.gz', 'database']);

        $this->assertSame('prepare', $state['phase']);
        $this->assertSame(';', $state['delimiter']);
        $this->assertSame([], $state['warnings']);
        $this->assertSame(['session_id' => 'sid', 'file' => 'b.sql.gz'], [
            'session_id' => $state['session_id'],
            'file' => $state['file'],
        ]);
    }

    public function testNormalizeFillsDefaultsAndRejectsUnknownType(): void
    {
        $state = $this->invoke('restore_session_normalize', [[]]);

        $this->assertSame('prepare', $state['phase']);
        $this->assertSame(';', $state['delimiter']);
        $this->assertSame([], $state['warnings']);
        $this->assertSame([], $state['errors']);
        $this->assertSame([], $state['tables']);
        $this->assertSame(0, $state['sql_offset']);

        $unknown = $this->invoke('restore_session_normalize', [['type' => 'lo_que_sea']]);
        $this->assertSame('complete', $unknown['type']);

        $emptyDelimiter = $this->invoke('restore_session_normalize', [['delimiter' => '   ']]);
        $this->assertSame(';', $emptyDelimiter['delimiter']);

        $customDelimiter = $this->invoke('restore_session_normalize', [['delimiter' => '//']]);
        $this->assertSame('//', $customDelimiter['delimiter']);
    }

    public function testStateFileSanitizesSessionIdAgainstTraversal(): void
    {
        $file = $this->manager->restore_session_state_file('../../etc/passwd');

        $this->assertStringStartsWith(sys_get_temp_dir() . DIRECTORY_SEPARATOR, $file);
        $this->assertSame(dirname($file), sys_get_temp_dir());
        $this->assertStringNotContainsString('..', basename($file));
        $this->assertStringNotContainsString('/', basename($file));
    }

    public function testSaveLoadAndForgetRoundTrip(): void
    {
        $sessionId = $this->uniqueSessionId();
        $state = $this->invoke('restore_session_normalize', [[
            'session_id' => $sessionId,
            'type' => 'database',
            'phase' => 'import',
            'sql_offset' => 6767,
            'delimiter' => '//',
            'statement_count' => 202,
        ]]);

        $this->invoke('restore_session_save', [$state]);

        $file = $this->manager->restore_session_state_file($sessionId);
        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4), 'el estado debe ser 0600');

        $loaded = $this->manager->restore_session_load($sessionId);
        $this->assertNotNull($loaded);
        $this->assertSame(6767, $loaded['sql_offset']);
        $this->assertSame('//', $loaded['delimiter']);
        $this->assertSame(202, $loaded['statement_count']);

        $this->manager->restore_session_forget($sessionId);
        $this->assertFileDoesNotExist($file);
        $this->assertNull($this->manager->restore_session_load($sessionId));
    }

    public function testLoadRejectsCorruptedState(): void
    {
        $sessionId = $this->uniqueSessionId();
        file_put_contents($this->manager->restore_session_state_file($sessionId), 'no es json');

        $this->assertNull($this->manager->restore_session_load($sessionId));
    }

    public function testIsStaleCoversMissingFreshAndOldState(): void
    {
        $sessionId = $this->uniqueSessionId();
        $this->assertTrue($this->manager->restore_session_is_stale($sessionId), 'sin estado es stale');

        $state = $this->invoke('restore_session_normalize', [[
            'session_id' => $sessionId,
            'updated_at' => time(),
        ]]);
        $this->invoke('restore_session_save', [$state]);
        $this->assertFalse($this->manager->restore_session_is_stale($sessionId));

        $state['updated_at'] = time() - 600;
        $this->invoke('restore_session_save', [$state]);
        $this->assertTrue($this->manager->restore_session_is_stale($sessionId));
    }

    public function testPhasePercentIsMonotonicAcrossTheNewPhaseChain(): void
    {
        $phases = ['prepare', 'decompress', 'users', 'files', 'drop', 'import', 'cleanup', 'done'];

        $previous = -1;
        foreach ($phases as $phase) {
            $percent = (int) $this->invoke('restore_phase_percent', [['phase' => $phase]]);
            $this->assertGreaterThanOrEqual($previous, $percent, 'el porcentaje no debe retroceder en ' . $phase);
            $previous = $percent;
        }

        $this->assertSame(100, (int) $this->invoke('restore_phase_percent', [['phase' => 'done']]));
    }

    public function testAllowedPathOnlyAcceptsTempAndBackupRoots(): void
    {
        $this->assertTrue((bool) $this->invoke('restore_session_allowed_path', [null]));
        $this->assertTrue((bool) $this->invoke('restore_session_allowed_path', ['']));
        $this->assertTrue((bool) $this->invoke(
            'restore_session_allowed_path',
            [sys_get_temp_dir() . '/algo/dump.sql']
        ));
        $this->assertFalse((bool) $this->invoke('restore_session_allowed_path', ['/etc/passwd']));
    }

    public function testSlowPhaseRegistersWarningOnlyOnce(): void
    {
        $state = $this->invoke('restore_session_normalize', [[]]);
        $startedAt = microtime(true) - 10;
        $deadline = $startedAt + 5;

        $args = [&$state, 'prepare', $startedAt, $deadline];
        $this->invoke('restore_add_warning_if_slow', $args);
        $this->assertCount(1, $state['warnings']);
        $this->assertStringContainsString('prepare', $state['warnings'][0]);

        $args = [&$state, 'prepare', $startedAt, $deadline];
        $this->invoke('restore_add_warning_if_slow', $args);
        $this->assertCount(1, $state['warnings'], 'no debe duplicar la advertencia');

        $fast = $this->invoke('restore_session_normalize', [[]]);
        $args = [&$fast, 'files', microtime(true), microtime(true) + 5];
        $this->invoke('restore_add_warning_if_slow', $args);
        $this->assertSame([], $fast['warnings'], 'una fase rápida no genera aviso');
    }

    public function testBudgetResolutionUsesGivenValueAndDefault(): void
    {
        $this->assertSame(2.5, (float) $this->invoke('restore_session_resolve_budget', [2.5]));
        $this->assertSame(5.0, (float) $this->invoke('restore_session_resolve_budget', [null]));
        $this->assertSame(5.0, (float) $this->invoke('restore_session_resolve_budget', [0.0]));
    }

    public function testSaveSurvivesInvalidUtf8InMessages(): void
    {
        $sessionId = $this->uniqueSessionId();
        $state = $this->invoke('restore_session_normalize', [[
            'session_id' => $sessionId,
            'phase' => 'import',
            'sql_offset' => 120,
            // Bytes inválidos: sin JSON_INVALID_UTF8_SUBSTITUTE el guardado
            // fallaba en silencio y el chunk siguiente reejecutaba sentencias.
            'errors' => ["Error SQL: \xB0\xC0\xFE latin1"],
        ]]);

        $this->invoke('restore_session_save', [$state]);

        $file = $this->manager->restore_session_state_file($sessionId);
        $this->assertFileExists($file);
        $this->assertNotFalse(json_decode((string) file_get_contents($file), true));

        $loaded = $this->manager->restore_session_load($sessionId);
        $this->assertNotNull($loaded);
        $this->assertSame(120, $loaded['sql_offset']);
        $this->assertNotEmpty($loaded['errors']);
    }

    public function testResponseExposesErrorAndWarningCounts(): void
    {
        $state = $this->invoke('restore_session_normalize', [[
            'errors' => ['uno', 'dos'],
            'warnings' => ['aviso'],
        ]]);

        $response = $this->invoke('restore_session_response', [true, false, null, 'import', 'ok', 50, $state]);

        $this->assertSame(2, $response['progress']['errors_count']);
        $this->assertSame(1, $response['progress']['warnings_count']);
        $this->assertSame(['uno', 'dos'], $response['progress']['errors']);
        $this->assertSame(['aviso'], $response['progress']['warnings']);
    }

    public function testResponseBoundsTheErrorDetail(): void
    {
        $errors = [];
        for ($i = 1; $i <= 25; $i++) {
            $errors[] = 'error ' . $i;
        }

        $state = $this->invoke('restore_session_normalize', [['errors' => $errors]]);
        $response = $this->invoke('restore_session_response', [true, false, null, 'import', 'ok', 50, $state]);

        $this->assertSame(25, $response['progress']['errors_count'], 'el contador es el total');
        $this->assertCount(10, $response['progress']['errors'], 'el detalle va acotado');
        $this->assertSame('error 25', $response['progress']['errors'][9], 'debe traer los últimos');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function transactionControlProvider(): array
    {
        return [
            'start transaction' => ['START TRANSACTION', true],
            'start transaction minuscula' => ['start transaction', true],
            'begin' => ['BEGIN', true],
            'begin work' => ['BEGIN WORK', true],
            'commit' => ['COMMIT', true],
            'rollback' => ['ROLLBACK', true],
            'set autocommit 0' => ['SET AUTOCOMMIT = 0', true],
            'set autocommit sin espacios' => ['SET autocommit=0', true],
            'set session autocommit' => ['SET SESSION autocommit = 0', true],
            'set global autocommit' => ['SET GLOBAL autocommit = 0', true],
            'foreign key checks' => ['SET FOREIGN_KEY_CHECKS = 0', false],
            'sql mode' => ['SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"', false],
            'time zone' => ['SET time_zone = "+00:00"', false],
            'insert' => ["INSERT INTO t VALUES (1)", false],
            'create table' => ['CREATE TABLE t (id INT)', false],
            'commit como prefijo de otra cosa' => ['COMMIT_TABLE', false],
        ];
    }

    #[DataProvider('transactionControlProvider')]
    public function testDetectsTransactionControlStatements(string $sql, bool $expected): void
    {
        $this->assertSame(
            $expected,
            (bool) $this->invoke('restore_is_transaction_control', [$sql]),
            'clasificación incorrecta para: ' . $sql
        );
    }
}
