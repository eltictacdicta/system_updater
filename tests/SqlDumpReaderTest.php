<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/SqlDumpReader.php';

class SqlDumpReaderTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    private function writeSql(string $content): string
    {
        $path = sys_get_temp_dir() . '/fs_sqldump_' . uniqid('', true) . '.sql';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<int, array{sql: string, offset_after: int}>
     */
    private function readAll(string $path): array
    {
        $reader = new \SystemUpdaterSqlDumpReader($path);
        $statements = [];
        while (($statement = $reader->nextStatement()) !== null) {
            $statements[] = $statement;
        }

        return $statements;
    }

    public function testTwoSimpleStatementsProduceTwoStatementsWithCorrectOffsets(): void
    {
        $sql = "SELECT 1;\nSELECT 2;\n";
        $reader = new \SystemUpdaterSqlDumpReader($this->writeSql($sql));

        $first = $reader->nextStatement();
        $this->assertNotNull($first);
        $this->assertSame('SELECT 1', $first['sql']);
        $this->assertSame(strlen("SELECT 1;\n"), $first['offset_after']);
        $this->assertSame($first['offset_after'], $reader->tell());
        $this->assertSame($first['offset_after'], $reader->offsetAfter());
        $this->assertSame(1, $reader->statementCount());

        $second = $reader->nextStatement();
        $this->assertNotNull($second);
        $this->assertSame('SELECT 2', $second['sql']);
        $this->assertSame(strlen($sql), $second['offset_after']);
        $this->assertSame(2, $reader->statementCount());

        $this->assertNull($reader->nextStatement());
        $this->assertSame(strlen($sql), $reader->tell());
    }

    public function testMultilineStatementIsJoined(): void
    {
        $sql = "INSERT INTO t (a, b)\nVALUES (1, 2),\n(3, 4);\n";
        $statements = $this->readAll($this->writeSql($sql));

        $this->assertCount(1, $statements);
        $this->assertSame("INSERT INTO t (a, b)\nVALUES (1, 2),\n(3, 4)", $statements[0]['sql']);
        $this->assertSame(strlen($sql), $statements[0]['offset_after']);
    }

    public function testDelimiterDirectiveDoesNotSplitProcedureBody(): void
    {
        $sql = "DELIMITER //\nCREATE PROCEDURE p()\nBEGIN\nSELECT 1;\nSELECT 2;\nEND//\nDELIMITER ;\nSELECT 3;\n";
        $statements = $this->readAll($this->writeSql($sql));

        $this->assertCount(2, $statements);
        $this->assertStringContainsString('CREATE PROCEDURE p()', $statements[0]['sql']);
        $this->assertStringContainsString('SELECT 1;', $statements[0]['sql']);
        $this->assertStringContainsString('SELECT 2;', $statements[0]['sql']);
        $this->assertStringContainsString('END', $statements[0]['sql']);
        $this->assertStringNotContainsString('SELECT 3', $statements[0]['sql']);
        $this->assertSame('SELECT 3', $statements[1]['sql']);
    }

    public function testCommentsAndBlankLinesDoNotProduceStatements(): void
    {
        $sql = "-- leading comment\n\n/* block comment\n   spanning lines */\nSELECT 1;\n";
        $statements = $this->readAll($this->writeSql($sql));

        $this->assertCount(1, $statements);
        $this->assertSame('SELECT 1', $statements[0]['sql']);
        $this->assertSame(strlen($sql), $statements[0]['offset_after']);
    }

    public function testCrlfLineEndingsAreSupported(): void
    {
        $sql = "SELECT 1;\r\nSELECT 2;\r\n";
        $statements = $this->readAll($this->writeSql($sql));

        $this->assertCount(2, $statements);
        $this->assertSame('SELECT 1', $statements[0]['sql']);
        $this->assertSame('SELECT 2', $statements[1]['sql']);
        $this->assertSame(strlen($sql), $statements[1]['offset_after']);
    }

    public function testEofReturnsNullAndStatementCountIsCorrect(): void
    {
        $reader = new \SystemUpdaterSqlDumpReader($this->writeSql("SELECT 1;\nSELECT 2;\nSELECT 3;\n"));

        $this->assertNotNull($reader->nextStatement());
        $this->assertNotNull($reader->nextStatement());
        $this->assertNotNull($reader->nextStatement());
        $this->assertSame(3, $reader->statementCount());
        $this->assertNull($reader->nextStatement());
        $this->assertNull($reader->nextStatement());
        $this->assertSame(3, $reader->statementCount());
    }

    public function testResumeFromTellDoesNotDuplicateOrSkipStatements(): void
    {
        $path = $this->writeSql("SELECT 1;\nSELECT 2;\nSELECT 3;\n");

        $firstReader = new \SystemUpdaterSqlDumpReader($path);
        $first = $firstReader->nextStatement();
        $this->assertNotNull($first);
        $this->assertSame('SELECT 1', $first['sql']);
        $safeOffset = $firstReader->tell();
        unset($firstReader);

        $secondReader = new \SystemUpdaterSqlDumpReader($path);
        $secondReader->seek($safeOffset);

        $second = $secondReader->nextStatement();
        $this->assertNotNull($second);
        $this->assertSame('SELECT 2', $second['sql']);

        $third = $secondReader->nextStatement();
        $this->assertNotNull($third);
        $this->assertSame('SELECT 3', $third['sql']);

        $this->assertNull($secondReader->nextStatement());
    }

    public function testTellNeverLandsInsideAStatement(): void
    {
        $path = $this->writeSql("SELECT 1;\nSELECT 2;\nSELECT 3;\n");
        $statements = $this->readAll($path);

        $this->assertCount(3, $statements);

        foreach ($statements as $index => $statement) {
            $resumed = new \SystemUpdaterSqlDumpReader($path);
            $resumed->seek($statement['offset_after']);
            $next = $resumed->nextStatement();

            if (isset($statements[$index + 1])) {
                $this->assertNotNull($next, 'offset_after de la sentencia ' . $index . ' no reanuda');
                $this->assertSame($statements[$index + 1]['sql'], $next['sql']);
            } else {
                $this->assertNull($next);
            }
        }
    }

    public function testLongLinesAreNotTruncated(): void
    {
        $values = str_repeat('a', 200000);
        $sql = "INSERT INTO t VALUES ('" . $values . "');\n";
        $statements = $this->readAll($this->writeSql($sql));

        $this->assertCount(1, $statements);
        $this->assertSame("INSERT INTO t VALUES ('" . $values . "')", $statements[0]['sql']);
        $this->assertSame(strlen($sql), $statements[0]['offset_after']);
    }

    public function testUnreadableFileReturnsNullWithoutWarnings(): void
    {
        $reader = new \SystemUpdaterSqlDumpReader(
            sys_get_temp_dir() . '/fs_missing_' . uniqid('', true) . '.sql'
        );

        $this->assertNull($reader->nextStatement());
        $this->assertSame(0, $reader->tell());
        $this->assertSame(0, $reader->statementCount());
    }

    public function testSeekRestoresCustomDelimiterOnResume(): void
    {
        $sql = "DELIMITER //\n"
            . "CREATE PROCEDURE p() BEGIN SELECT 1; END//\n"
            . "SELECT 20//\n"
            . "SELECT 30//\n";
        $path = $this->writeSql($sql);

        $first = new \SystemUpdaterSqlDumpReader($path);
        $statement = $first->nextStatement();
        $this->assertNotNull($statement);
        $this->assertSame('CREATE PROCEDURE p() BEGIN SELECT 1; END', $statement['sql']);
        $this->assertSame('//', $first->delimiter(), 'debe exponer el delimitador vigente');

        $offset = $first->tell();
        $delimiter = $first->delimiter();
        unset($first);

        $resumed = new \SystemUpdaterSqlDumpReader($path);
        $resumed->seek($offset, $delimiter);

        $second = $resumed->nextStatement();
        $this->assertNotNull($second);
        $this->assertSame('SELECT 20', $second['sql'], 'reanudar no debe corromper la sentencia');

        $third = $resumed->nextStatement();
        $this->assertNotNull($third);
        $this->assertSame('SELECT 30', $third['sql']);
    }

    public function testBlockCommentWithTrailingSqlKeepsTheStatement(): void
    {
        $statements = $this->readAll($this->writeSql("/* comentario */ SELECT 1;\nSELECT 2;\n"));

        $this->assertCount(2, $statements);
        $this->assertSame('SELECT 1', $statements[0]['sql']);
        $this->assertSame('SELECT 2', $statements[1]['sql']);
    }

    public function testMultipleStatementsOnOneLineAreReported(): void
    {
        $reader = new \SystemUpdaterSqlDumpReader($this->writeSql("SELECT 1; SELECT 2;\n"));

        $statement = $reader->nextStatement();
        $this->assertNotNull($statement);
        $this->assertNotEmpty(
            $reader->issues(),
            'una línea con dos sentencias debe reportarse en vez de perderse en silencio'
        );
    }

    public function testUnquotedDelimiterInsideStringIsNotReported(): void
    {
        $reader = new \SystemUpdaterSqlDumpReader(
            $this->writeSql("INSERT INTO t VALUES ('a;b');\n")
        );

        $statement = $reader->nextStatement();
        $this->assertNotNull($statement);
        $this->assertSame("INSERT INTO t VALUES ('a;b')", $statement['sql']);
        $this->assertSame([], $reader->issues(), 'un ; dentro de un literal no es multi-sentencia');
    }

    public function testEofWithoutTrailingDelimiterStillReturnsTheStatement(): void
    {
        $statements = $this->readAll($this->writeSql('SELECT 1'));

        $this->assertCount(1, $statements);
        $this->assertSame('SELECT 1', $statements[0]['sql']);
    }

    public function testEmptyFileProducesNoStatements(): void
    {
        $reader = new \SystemUpdaterSqlDumpReader($this->writeSql(''));

        $this->assertNull($reader->nextStatement());
        $this->assertSame(0, $reader->tell());
        $this->assertSame(0, $reader->statementCount());
    }

    public function testCommentsOnlyFileProducesNoStatements(): void
    {
        $reader = new \SystemUpdaterSqlDumpReader(
            $this->writeSql("-- uno\n/* dos */\n\n-- tres\n")
        );

        $this->assertNull($reader->nextStatement());
        $this->assertSame(0, $reader->statementCount());
        $this->assertSame(strlen("-- uno\n/* dos */\n\n-- tres\n"), $reader->tell());
    }

    public function testResumeExactlyAtEofReturnsNull(): void
    {
        $sql = "SELECT 1;\nSELECT 2;\n";
        $path = $this->writeSql($sql);

        $reader = new \SystemUpdaterSqlDumpReader($path);
        $this->readAllStatements($reader);

        $reader->seek(strlen($sql));
        $this->assertNull($reader->nextStatement());
        $this->assertSame(strlen($sql), $reader->tell());
    }

    private function readAllStatements(\SystemUpdaterSqlDumpReader $reader): void
    {
        while ($reader->nextStatement() !== null) {
        }
    }
}
