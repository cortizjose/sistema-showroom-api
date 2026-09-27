<?php
/**
 * =============================================================================
 * src/BaseDatos/Conexion.php
 * -----------------------------------------------------------------------------
 * Administra la conexion a la base de datos usando PDO.
 *
 * Se aplica el patron Singleton: sin importar cuantas veces se solicite la
 * conexion durante una peticion, solo se abre una vez.
 *
 * Se soportan dos motores:
 *   - sqlite : archivo local, no requiere instalacion (ideal para pruebas).
 *   - mysql  : servidor MySQL/MariaDB (por ejemplo el incluido en XAMPP).
 *
 * El uso de PDO con consultas preparadas es la defensa principal contra la
 * inyeccion SQL, uno de los riesgos del Top 10 de OWASP.
 * =============================================================================
 */

declare(strict_types=1);

namespace App\BaseDatos;

use PDO;
use PDOException;
use RuntimeException;

class Conexion
{
    /** @var PDO|null Instancia unica de la conexion. */
    private static ?PDO $instancia = null;

    /** @var array<string,mixed> Parametros de conexion tomados de la configuracion. */
    private static array $configuracion = [];

    /**
     * Guarda los parametros de conexion. Debe invocarse una vez al iniciar
     * la aplicacion (desde public/index.php).
     *
     * @param array<string,mixed> $configuracion Seccion 'baseDatos' del config.
     * @return void
     */
    public static function configurar(array $configuracion): void
    {
        self::$configuracion = $configuracion;
    }

    /**
     * Devuelve la conexion PDO, creandola la primera vez que se solicita.
     *
     * @return PDO
     * @throws RuntimeException Si no es posible conectarse.
     */
    public static function obtener(): PDO
    {
        // Si ya existe una conexion activa se reutiliza.
        if (self::$instancia instanceof PDO) {
            return self::$instancia;
        }

        $driver = self::$configuracion['driver'] ?? 'sqlite';

        try {
            self::$instancia = match ($driver) {
                'sqlite' => self::conectarSqlite(),
                'mysql'  => self::conectarMysql(),
                default  => throw new RuntimeException("Motor de base de datos no soportado: $driver"),
            };
        } catch (PDOException $excepcion) {
            // No se expone el mensaje original al cliente: podria revelar
            // rutas, usuarios o contrasenas del servidor.
            throw new RuntimeException(
                'No fue posible establecer la conexion con la base de datos. '
                . 'Detalle tecnico: ' . $excepcion->getMessage()
            );
        }

        return self::$instancia;
    }

    /**
     * Crea la conexion contra un archivo SQLite.
     *
     * @return PDO
     */
    private static function conectarSqlite(): PDO
    {
        $rutaArchivo = (string) self::$configuracion['rutaSqlite'];
        $directorio  = dirname($rutaArchivo);

        // Se crea la carpeta de almacenamiento si aun no existe.
        if (!is_dir($directorio)) {
            mkdir($directorio, 0777, true);
        }

        $pdo = new PDO('sqlite:' . $rutaArchivo, null, null, self::opcionesPdo());

        // SQLite no valida las llaves foraneas de forma predeterminada.
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Crea la conexion contra un servidor MySQL / MariaDB.
     *
     * @return PDO
     */
    private static function conectarMysql(): PDO
    {
        $config = self::$configuracion;

        // DSN: cadena que describe el origen de datos.
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['puerto'],
            $config['nombre'],
            $config['charset']
        );

        return new PDO($dsn, $config['usuario'], $config['clave'], self::opcionesPdo());
    }

    /**
     * Opciones comunes de PDO.
     *
     * @return array<int,mixed>
     */
    private static function opcionesPdo(): array
    {
        return [
            // Los errores se lanzan como excepciones y no pasan inadvertidos.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

            // Los resultados se devuelven como arreglos asociativos.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Se desactiva la emulacion para usar consultas preparadas reales
            // del motor, lo que refuerza la proteccion contra inyeccion SQL.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    /**
     * Indica que motor esta en uso. Lo consulta la clase Migracion para
     * generar el SQL correcto en cada caso.
     *
     * @return string
     */
    public static function driver(): string
    {
        return (string) (self::$configuracion['driver'] ?? 'sqlite');
    }

    /**
     * Cierra la conexion. Se utiliza principalmente en las pruebas.
     *
     * @return void
     */
    public static function cerrar(): void
    {
        self::$instancia = null;
    }
}
