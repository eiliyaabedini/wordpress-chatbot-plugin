<?php
/**
 * Dependency Injection Container
 *
 * Simple service container for managing dependencies throughout the plugin.
 * Supports singleton bindings and factory functions.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Container
 *
 * Provides dependency injection capabilities for the chatbot plugin.
 */
class Chatbot_Container {

    /**
     * The single instance of the container.
     *
     * @var Chatbot_Container|null
     */
    private static $instance = null;

    /**
     * Array of registered bindings (factories).
     *
     * @var array<string, callable>
     */
    private $bindings = array();

    /**
     * Array of resolved singleton instances.
     *
     * @var array<string, object>
     */
    private $instances = array();

    /**
     * Array of singleton binding keys.
     *
     * @var array<string, bool>
     */
    private $singletons = array();

    /**
     * Get the singleton instance of the container.
     *
     * @return Chatbot_Container
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        // Private constructor
    }

    /**
     * Register a binding in the container.
     *
     * @param string   $abstract The abstract type or interface name.
     * @param callable $factory  Factory function that creates the instance.
     * @return void
     */
    public function bind($abstract, $factory) {
        $this->bindings[$abstract] = $factory;
        unset($this->singletons[$abstract]);
        unset($this->instances[$abstract]);
    }

    /**
     * Register a singleton binding in the container.
     *
     * The factory will only be called once, and the same instance
     * will be returned on subsequent calls.
     *
     * @param string   $abstract The abstract type or interface name.
     * @param callable $factory  Factory function that creates the instance.
     * @return void
     */
    public function singleton($abstract, $factory) {
        $this->bindings[$abstract] = $factory;
        $this->singletons[$abstract] = true;
        unset($this->instances[$abstract]);
    }

    /**
     * Register an existing instance in the container.
     *
     * @param string $abstract The abstract type or interface name.
     * @param object $instance The instance to register.
     * @return void
     */
    public function instance($abstract, $instance) {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
    }

    /**
     * Resolve and return an instance from the container.
     *
     * @param string $abstract The abstract type or interface name.
     * @return object The resolved instance.
     * @throws Exception If no binding is found for the abstract.
     */
    public function make($abstract) {
        // Return cached singleton instance if available
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Check if we have a binding
        if (!isset($this->bindings[$abstract])) {
            throw new Exception(
                sprintf(
                    'Chatbot Container: No binding found for "%s". Did you forget to register it in the Service Provider?',
                    $abstract
                )
            );
        }

        // Resolve the binding
        $factory = $this->bindings[$abstract];
        $instance = $factory($this);

        // Cache singleton instances
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Check if a binding exists in the container.
     *
     * @param string $abstract The abstract type or interface name.
     * @return bool True if the binding exists.
     */
    public function has($abstract) {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Remove a binding from the container.
     *
     * @param string $abstract The abstract type or interface name.
     * @return void
     */
    public function forget($abstract) {
        unset($this->bindings[$abstract]);
        unset($this->instances[$abstract]);
        unset($this->singletons[$abstract]);
    }

    /**
     * Reset the container (useful for testing).
     *
     * @return void
     */
    public function reset() {
        $this->bindings = array();
        $this->instances = array();
        $this->singletons = array();
    }

    /**
     * Get all registered binding keys.
     *
     * @return array<string> List of registered abstract types.
     */
    public function get_bindings() {
        return array_keys($this->bindings);
    }
}
