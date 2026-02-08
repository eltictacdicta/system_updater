<?php
/**
 * Inicialización del Plugin system_updater
 * 
 * Este archivo se ejecuta cuando el plugin es cargado.
 * Registra los controladores del plugin en el sistema.
 * 
 * Diseñado para ser compatible con versiones legacy del framework.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

/**
 * Clase de inicialización del plugin
 */
class Init
{
    /**
     * Lista de controladores que proporciona este plugin
     * @var array
     */
    public static $controllers = [
        'admin_updater' => [
            'name' => 'Actualizador',
            'menu' => 'admin',
            'icon' => 'fa-upload',
            'file' => 'controller/admin_updater.php'
        ],
        'admin_plugin_store' => [
            'name' => 'Tienda de Plugins',
            'menu' => 'admin',
            'icon' => 'fa-shopping-cart',
            'file' => 'controller/admin_plugin_store.php'
        ]
    ];

    /**
     * Constructor - se ejecuta al cargar el plugin
     */
    public function __construct()
    {
        // Registrar hook para cuando se solicite un controlador de este plugin
        // Esto permite que el framework enrute las peticiones correctamente
    }

    /**
     * Carga y ejecuta un controlador del plugin
     * 
     * @param string $controllerName Nombre del controlador
     * @return object|null Instancia del controlador o null si no existe
     */
    public static function loadController($controllerName)
    {
        if (!isset(self::$controllers[$controllerName])) {
            return null;
        }

        $controllerFile = __DIR__ . '/' . self::$controllers[$controllerName]['file'];

        if (!file_exists($controllerFile)) {
            return null;
        }

        require_once $controllerFile;

        if (class_exists($controllerName)) {
            $controller = new $controllerName();
            if (method_exists($controller, 'handle')) {
                $controller->handle();
            }
            return $controller;
        }

        return null;
    }

    /**
     * Obtiene la lista de controladores disponibles
     * 
     * @return array
     */
    public static function getControllers()
    {
        return self::$controllers;
    }

    /**
     * Verifica si un controlador pertenece a este plugin
     * 
     * @param string $controllerName
     * @return bool
     */
    public static function hasController($controllerName)
    {
        return isset(self::$controllers[$controllerName]);
    }
}

// Registrar los controladores si el framework los solicita
// Esto se hace comprobando si hay una página solicitada que coincida
$requestedPage = filter_input(INPUT_GET, 'page');
if ($requestedPage && Init::hasController($requestedPage)) {
    // El framework cargará el controlador a través de Init::loadController()
}
