<?php
/**
 * config.php — Connessione al database (PDO / MySQL) per stack LAMP.
 *
 * Legge le credenziali da un file .env nella root del progetto.
 * Se il file non esiste, o una singola chiave manca, vengono usati default sicuri.
 *
 * Uso:
 *     require __DIR__ . '/config.php';
 *     $rows = $pdo->query('SELECT * FROM servers')->fetchAll();
 *
 * La variabile $pdo è disponibile subito dopo l'include;
 * il file restituisce comunque $pdo, quindi è possibile anche:
 *     $pdo = require __DIR__ . '/config.php';
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Lettura del file .env
// ---------------------------------------------------------------------------

/**
 * Parser minimale di file .env.
 * Gestisce: righe vuote, commenti (#), prefisso "export ", valori tra
 * virgolette singole o doppie, e valori contenenti "=".
 *
 * @param string $path Percorso assoluto del file .env
 * @return array<string, string>
 */
$loadEnvFile = static function (string $path): array {
    $vars = [];

    if (!is_file($path) || !is_readable($path)) {
        return $vars; // nessun .env: si procede con i default
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $vars;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Salta righe vuote e commenti.
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Supporta la forma "export KEY=value".
        if (strncmp($line, 'export ', 7) === 0) {
            $line = trim(substr($line, 7));
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        $parts = explode('=', $line, 2);
        $key   = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '') {
            continue;
        }

        // Rimuove le virgolette di apertura/chiusura, se presenti.
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // La prima occorrenza della chiave vince.
        if (!array_key_exists($key, $vars)) {
            $vars[$key] = $value;
        }
    }

    return $vars;
};

$env = $loadEnvFile(__DIR__ . '/.env');

/**
 * Precedenza: .env  ->  variabile d'ambiente del processo  ->  default.
 * Un valore vuoto è trattato come "non impostato".
 */
$envValue = static function (string $key, string $default) use ($env): string {
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }

    $fromProcess = getenv($key);
    if (is_string($fromProcess) && $fromProcess !== '') {
        return $fromProcess;
    }

    return $default;
};

// ---------------------------------------------------------------------------
// 2. Configurazione — default sicuri (nessuna credenziale reale nel codice)
// ---------------------------------------------------------------------------

$dbHost    = $envValue('DB_HOST', '127.0.0.1');
$dbPort    = (int) $envValue('DB_PORT', '3306');
$dbName    = $envValue('DB_NAME', 'server_health');
$dbUser    = $envValue('DB_USER', 'app_user');   // utente applicativo, non root
$dbPass    = $envValue('DB_PASS', '');           // nessuna password di default
$dbCharset = $envValue('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// 3. Connessione PDO
// ---------------------------------------------------------------------------

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $dbHost,
    $dbPort,
    $dbName,
    $dbCharset
);

$options = [
    // Errori sempre come eccezioni: niente fallimenti silenziosi.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Fetch di default come array associativo.
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Prepared statement reali lato MySQL, non emulati.
    PDO::ATTR_EMULATE_PREPARES   => false,
    // I tipi numerici restano numerici (niente stringhe "1").
    PDO::ATTR_STRINGIFY_FETCHES  => false,
    // Connessione persistente disattivata: più prevedibile in ambiente LAMP.
    PDO::ATTR_PERSISTENT         => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // Il dettaglio tecnico finisce solo nei log del server.
    // Il DSN non contiene la password, ma il messaggio resta comunque interno.
    error_log('[config.php] Connessione al database fallita: ' . $e->getMessage());

    // Messaggio generico verso l'esterno: nessun riferimento a host,
    // utente, password o nome del database.
    throw new RuntimeException(
        'Impossibile connettersi al database. Contattare l\'amministratore.',
        0,
        $e
    );
}

return $pdo;
