<?php
/**
 * Lector reanudable de dumps SQL para el plugin system_updater.
 *
 * Divide un volcado SQL en sentencias completas manteniendo un offset de bytes
 * SIEMPRE seguro (límite de sentencia). Reabrir el archivo en `tell()` y seguir
 * leyendo nunca arranca en medio de una sentencia ni duplica/salta sentencias.
 *
 * Sin mysqli, sin namespaces y sin dependencias externas: es testeable de forma
 * aislada y funciona igual en el import nativo por pasos y en los tests.
 */

class SystemUpdaterSqlDumpReader
{
    /**
     * Tamaño de lectura por bloque (1 MiB). Se concatenan bloques hasta
     * completar una línea real, de modo que las líneas largas no rompen la
     * detección de delimitador.
     */
    private const READ_CHUNK = 1048576;

    /**
     * @var string
     */
    private $path;

    /**
     * @var resource|null
     */
    private $handle;

    /**
     * Delimitador de sentencia vigente (por defecto `;`).
     *
     * @var string
     */
    private $delimiter = ';';

    /**
     * Texto acumulado de la sentencia en curso.
     *
     * @var string
     */
    private $buffer = '';

    /**
     * Offset seguro de reanudación (lo que devuelve `tell()`).
     *
     * @var int
     */
    private $resumeOffset = 0;

    /**
     * `offset_after` de la última sentencia devuelta.
     *
     * @var int
     */
    private $lastOffsetAfter = 0;

    /**
     * Cantidad de sentencias devueltas por esta instancia.
     *
     * @var int
     */
    private $statementCount = 0;

    /**
     * Indica si estamos dentro de un comentario de bloque multilínea.
     *
     * @var bool
     */
    private $inBlockComment = false;

    /**
     * Anomalías no fatales del dump que el import debe reportar en vez de
     * resolver en silencio (por ejemplo, varias sentencias en una línea).
     *
     * @var list<string>
     */
    private $issues = array();

    /**
     * @param string $path Ruta al archivo SQL.
     */
    public function __construct(string $path)
    {
        $this->path = $path;
        $this->open();
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * Posiciona el lector en un offset de bytes seguro.
     *
     * @param int $offset
     * @return void
     */
    public function seek(int $offset, ?string $delimiter = null): void
    {
        if ($offset < 0) {
            $offset = 0;
        }

        if ($this->handle === null) {
            $this->open();
        }

        if ($this->handle === null) {
            return;
        }

        fseek($this->handle, $offset, SEEK_SET);
        $this->delimiter = ($delimiter === null || $delimiter === '') ? ';' : $delimiter;
        $this->buffer = '';
        $this->inBlockComment = false;
        $this->resumeOffset = $offset;
        $this->lastOffsetAfter = $offset;
    }

    /**
     * Delimitador vigente. Hay que persistirlo entre pasos: si se reanuda
     * dentro de una región con DELIMITER custom y se vuelve a `;`, la sentencia
     * siguiente se corta mal.
     *
     * @return string
     */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Anomalías detectadas durante la lectura.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * Offset seguro de reanudación: siempre un límite de sentencia.
     *
     * @return int
     */
    public function tell(): int
    {
        return $this->resumeOffset;
    }

    /**
     * Alias de `offset_after` de la última sentencia devuelta.
     *
     * @return int
     */
    public function offsetAfter(): int
    {
        return $this->lastOffsetAfter;
    }

    /**
     * Cantidad de sentencias devueltas por esta instancia.
     *
     * @return int
     */
    public function statementCount(): int
    {
        return $this->statementCount;
    }

    /**
     * Devuelve la próxima sentencia completa, o `null` en EOF.
     *
     * @return array{sql: string, offset_after: int}|null
     */
    public function nextStatement(): ?array
    {
        if ($this->handle === null) {
            return null;
        }

        while (true) {
            $lineStart = ftell($this->handle);
            if ($lineStart === false) {
                $lineStart = $this->resumeOffset;
            }

            $line = $this->readLine();

            if ($line === false) {
                return $this->finishAtEof($lineStart);
            }

            $rtrimmed = rtrim($line, "\r\n");
            $trimmed = trim($rtrimmed);

            if ($this->inBlockComment) {
                if (strpos($line, '*/') !== false) {
                    $this->inBlockComment = false;
                }
                if ($this->buffer === '') {
                    $this->advanceSafeOffset();
                    continue;
                }
                $this->buffer .= $line;
                continue;
            }

            if ($trimmed === '') {
                if ($this->buffer === '') {
                    $this->advanceSafeOffset();
                    continue;
                }
                $this->buffer .= $line;
                continue;
            }

            // Directiva DELIMITER: no es una sentencia, sólo cambia el delimitador.
            if ($this->buffer === '' && preg_match('/^DELIMITER\s+(\S+)$/i', $trimmed, $matches)) {
                $this->delimiter = $matches[1];
                $this->advanceSafeOffset();
                continue;
            }

            // Comentarios de línea. Los comentarios condicionales de MySQL
            // (los que empiezan por el prefijo de comentario más "!") SÍ son
            // ejecutables y se conservan.
            if (strpos($trimmed, '--') === 0) {
                if ($this->buffer === '') {
                    $this->advanceSafeOffset();
                    continue;
                }
                $this->buffer .= $line;
                continue;
            }

            // Comentarios de bloque reales (no condicionales). Si el bloque
            // cierra en la misma línea se conserva el SQL que venga después;
            // antes se descartaba la línea entera y se perdía la sentencia.
            if (strpos($trimmed, '/*') === 0 && strpos($trimmed, '/*!') !== 0) {
                if (strpos($line, '*/') === false) {
                    $this->inBlockComment = true;
                    if ($this->buffer === '') {
                        $this->advanceSafeOffset();
                        continue;
                    }
                    $this->buffer .= $line;
                    continue;
                }

                $remainder = substr($line, strpos($line, '*/') + 2);
                if (trim($remainder) === '') {
                    if ($this->buffer === '') {
                        $this->advanceSafeOffset();
                    } else {
                        $this->buffer .= $line;
                    }
                    continue;
                }

                $line = $remainder;
                $rtrimmed = rtrim($line, "\r\n");
                $trimmed = trim($rtrimmed);
            }

            // Línea SQL real: acumular.
            $this->buffer .= $line;

            $rtrimmedBuffer = rtrim($this->buffer);
            $delimiterLength = strlen($this->delimiter);
            if ($delimiterLength === 0 || substr($rtrimmedBuffer, -$delimiterLength) !== $this->delimiter) {
                continue;
            }

            $sql = trim(substr($rtrimmedBuffer, 0, -$delimiterLength));
            $this->buffer = '';

            // Varias sentencias en una misma línea física: no se parten (haría
            // falta un parser con estado de comillas y debilitaría el
            // invariante de reanudación exacta de la cola pendiente). Se
            // reporta para que el import lo muestre en vez de perderlas en
            // silencio.
            if ($this->findUnquotedDelimiter($sql) !== null) {
                $this->addIssue(
                    'El dump tiene varias sentencias en una misma línea física (offset '
                    . $lineStart . '): se importa como una sola y MySQL la rechazará.'
                );
            }

            $offsetAfter = ftell($this->handle);
            if ($offsetAfter === false) {
                $offsetAfter = $lineStart;
            }
            $this->resumeOffset = $offsetAfter;
            $this->lastOffsetAfter = $offsetAfter;

            if ($sql === '') {
                continue; // línea con sólo el delimitador
            }

            $this->statementCount++;

            return array(
                'sql' => $sql,
                'offset_after' => $offsetAfter,
            );
        }
    }

    /**
     * Registra una anomalía una sola vez.
     *
     * @param string $message
     * @return void
     */
    private function addIssue(string $message): void
    {
        if (in_array($message, $this->issues, true)) {
            return;
        }

        $this->issues[] = $message;
    }

    /**
     * Busca el delimitador vigente fuera de comillas simples, dobles o backticks.
     * Sirve para detectar varias sentencias en una línea sin confundirse con un
     * `;` dentro de un literal.
     *
     * @param string $text
     * @return int|null
     */
    private function findUnquotedDelimiter(string $text): ?int
    {
        $delimiterLength = strlen($this->delimiter);
        if ($delimiterLength === 0) {
            return null;
        }

        $length = strlen($text);
        $quote = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($char === '\\' && $quote !== '`') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($i + 1 < $length && $text[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $quote = '';
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }

            if (substr($text, $i, $delimiterLength) === $this->delimiter) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Abre el archivo en modo binario, sin emitir warnings no controlados.
     *
     * @return void
     */
    private function open(): void
    {
        $this->handle = null;

        if (!is_file($this->path) || !is_readable($this->path)) {
            return;
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return;
        }

        $this->handle = $handle;
    }

    /**
     * Lee una línea completa, concatenando bloques para líneas muy largas.
     *
     * @return string|false
     */
    private function readLine()
    {
        if ($this->handle === null) {
            return false;
        }

        $line = fgets($this->handle, self::READ_CHUNK);
        if ($line === false) {
            return false;
        }

        while (substr($line, -1) !== "\n" && !feof($this->handle)) {
            $more = fgets($this->handle, self::READ_CHUNK);
            if ($more === false) {
                break;
            }
            $line .= $more;
        }

        return $line;
    }

    /**
     * Avanza el offset seguro hasta la posición actual del cursor.
     *
     * @return void
     */
    private function advanceSafeOffset(): void
    {
        $position = ftell($this->handle);
        if ($position === false) {
            return;
        }

        $this->resumeOffset = $position;
        $this->lastOffsetAfter = $position;
    }

    /**
     * Cierra el archivo en EOF, devolviendo la sentencia pendiente si existe.
     *
     * @param int $lineStart
     * @return array{sql: string, offset_after: int}|null
     */
    private function finishAtEof(int $lineStart): ?array
    {
        $eof = ftell($this->handle);
        if ($eof === false) {
            $eof = $lineStart;
        }

        if (trim($this->buffer) !== '') {
            $sql = trim($this->buffer);
            $delimiterLength = strlen($this->delimiter);
            if ($delimiterLength > 0 && substr($sql, -$delimiterLength) === $this->delimiter) {
                $sql = trim(substr($sql, 0, -$delimiterLength));
            }
            $this->buffer = '';

            $this->resumeOffset = $eof;
            $this->lastOffsetAfter = $eof;

            if ($sql !== '') {
                $this->statementCount++;
                return array(
                    'sql' => $sql,
                    'offset_after' => $eof,
                );
            }
        }

        $this->resumeOffset = $eof;
        return null;
    }
}
