<?php
/**
 * index.php — Dashboard di stato del server.
 *
 * Pagina principale dell'applicazione: raccoglie e mostra alcune informazioni
 * di salute della macchina che ospita il sito (stack LAMP).
 *
 * Cosa fa:
 *   1. Include config.php dentro un try/catch per ottenere la connessione $pdo.
 *      Se la connessione fallisce la pagina NON si interrompe: mostra il badge
 *      rosso "Database: errore" e continua con le altre sezioni.
 *   2. Spazio disco  -> `df -h /`   (tabella con Filesystem/Size/Used/Avail/Use%)
 *
 * Sicurezza:
 *   - Se shell_exec non è disponibile (funzione disabilitata in php.ini)
 *     viene mostrato "non disponibile" invece di generare un errore fatale.
 *   - Tutto ciò che proviene dal sistema (output di shell) passa da
 *     htmlspecialchars() prima di essere stampato.
 *   - Il messaggio di errore del database non viene mai mostrato all'utente:
 *     finisce solo in error_log().
 *
 * Dipendenze: solo PHP nativo. Richiede config.php e style.css nella stessa cartella.
 */

declare(strict_types=1);

// ===========================================================================
// Helper di sicurezza e di sistema
// ===========================================================================

/** Esegue l'escape di qualsiasi stringa proveniente dall'esterno per l'HTML. */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Verifica se la shell è realmente utilizzabile.
 * In PHP le funzioni disabilitate via `disable_functions` vengono rimosse dalla
 * function table, quindi function_exists() è sufficiente a rilevarle.
 */
function shell_available(): bool
{
    return function_exists('shell_exec')
        && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
}

/**
 * Esegue un comando e restituisce l'output grezzo, oppure null se la shell
 * non è disponibile o il comando non produce nulla.
 */
function run_command(string $command): ?string
{
    if (!shell_available()) {
        return null;
    }

    $output = @shell_exec($command . ' 2>/dev/null');

    if (!is_string($output)) {
        return null;
    }

    $output = trim($output);

    return $output === '' ? null : $output;
}

// ===========================================================================
// 1. Connessione al database (l'errore non deve fermare la pagina)
// ===========================================================================

$pdo = null;

try {
    // config.php restituisce $pdo e imposta la variabile nello scope corrente.
    $pdo = require __DIR__ . '/config.php';

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('config.php non ha restituito un oggetto PDO valido.');
    }

    // Verifica minima: il badge verde significa "connessione utilizzabile".
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    // Dettaglio tecnico solo nei log del server, mai nella pagina.
    error_log('[index.php] Database non disponibile: ' . $e->getMessage());

    $pdo = null;
}

$dbOk = $pdo instanceof PDO;

// ===========================================================================
// 2. Spazio disco — df -h /
// ===========================================================================

/** @var array<int, array{filesystem:string,size:string,used:string,avail:string,use:string}> $diskRows */
$diskRows    = [];
$diskRaw     = run_command('df -h /');
$diskMessage = null;

if ($diskRaw === null) {
    $diskMessage = shell_available()
        ? 'Nessun output da "df -h /".'
        : 'non disponibile (shell_exec disabilitato)';
} else {
    $lines       = preg_split('/\R/', $diskRaw) ?: [];
    $pendingName = [];

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue; // intestazione originale, la ricostruiamo noi
        }

        $columns = preg_split('/\s+/', trim($line)) ?: [];
        $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== ''));

        if ($columns === []) {
            continue;
        }

        // df spezza su due righe i filesystem con nome molto lungo: la prima
        // riga contiene solo il nome, la seconda le 5 colonne numeriche.
        // Una riga "dati" ha almeno 5 colonne e termina con la percentuale.
        $tail = count($columns) >= 5 ? array_slice($columns, -5) : [];
        $isDataRow = $tail !== [] && strpos($tail[3], '%') !== false;

        if (!$isDataRow) {
            $pendingName = array_merge($pendingName, $columns);
            continue;
        }

        $name = implode(' ', array_merge($pendingName, array_slice($columns, 0, count($columns) - 5)));
        $pendingName = [];

        $diskRows[] = [
            'filesystem' => $name,
            'size'       => $tail[0],
            'used'       => $tail[1],
            'avail'      => $tail[2],
            'use'        => $tail[3],
        ];
    }

    if ($diskRows === []) {
        $diskMessage = 'Output di "df -h /" non interpretabile.';
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stato del server — Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="page-header">
        <h1>Stato del server</h1>
        <p class="badges">
            <?php if ($dbOk): ?>
                <span class="badge badge-ok">Database: OK</span>
            <?php else: ?>
                <span class="badge badge-error">Database: errore</span>
            <?php endif; ?>
        </p>
        <p class="timestamp">Rilevato il <?= h(date('d/m/Y H:i:s')) ?></p>
    </header>

    <main>
        <section class="card">
            <h2>Spazio disco (<code>df -h /</code>)</h2>

            <?php if ($diskMessage !== null): ?>
                <p class="notice"><?= h($diskMessage) ?></p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Filesystem</th>
                            <th scope="col">Size</th>
                            <th scope="col">Used</th>
                            <th scope="col">Avail</th>
                            <th scope="col">Use%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diskRows as $row): ?>
                            <tr>
                                <td><?= h($row['filesystem']) ?></td>
                                <td><?= h($row['size']) ?></td>
                                <td><?= h($row['used']) ?></td>
                                <td><?= h($row['avail']) ?></td>
                                <td><?= h($row['use']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>

    <footer class="page-footer">
        <p>Dashboard di monitoraggio — PHP <?= h(PHP_VERSION) ?></p>
    </footer>
</body>
</html>
