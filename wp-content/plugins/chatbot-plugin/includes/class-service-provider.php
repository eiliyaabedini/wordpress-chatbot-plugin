<?php
/**
 * Service Provider
 *
 * Registers all service bindings in the dependency injection container.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Service_Provider
 *
 * Registers all services and their dependencies in the container.
 */
class Chatbot_Service_Provider {

    /**
     * The container instance.
     *
     * @var Chatbot_Container
     */
    private $container;

    /**
     * Constructor.
     *
     * @param Chatbot_Container $container The DI container.
     */
    public function __construct(Chatbot_Container $container) {
        $this->container = $container;
    }

    /**
     * Register all services in the container.
     *
     * @return void
     */
    public function register() {
        $this->register_repositories();
        $this->register_ai_services();
        $this->register_messaging_services();
        $this->register_legacy_adapters();
    }

    /**
     * Register repository bindings.
     *
     * @return void
     */
    private function register_repositories() {
        // Conversation Repository
        $this->container->singleton(
            'Chatbot_Conversation_Repository',
            function ($container) {
                return new Chatbot_WP_Conversation_Repository();
            }
        );

        // Message Repository
        $this->container->singleton(
            'Chatbot_Message_Repository',
            function ($container) {
                return new Chatbot_WP_Message_Repository();
            }
        );

        // Configuration Repository
        $this->container->singleton(
            'Chatbot_Configuration_Repository',
            function ($container) {
                return new Chatbot_WP_Configuration_Repository();
            }
        );
    }

    /**
     * Register AI service bindings.
     *
     * @return void
     */
    private function register_ai_services() {
        // AIPass configuration (hardcoded in plugin)
        $aipass_base_url = 'https://aipass.one';
        $aipass_client_id = 'client_B44Woc2V6Jc_ywmlbIKLEA';

        // API Client for AIPass
        $this->container->singleton(
            'Chatbot_API_Client',
            function ($container) use ($aipass_base_url) {
                return new Chatbot_API_Client($aipass_base_url);
            }
        );

        // Token Manager for AIPass
        $this->container->singleton(
            'Chatbot_Token_Manager',
            function ($container) use ($aipass_client_id) {
                return new Chatbot_Token_Manager(
                    $container->make('Chatbot_API_Client'),
                    $aipass_client_id
                );
            }
        );

        // AIPass Provider
        $this->container->singleton(
            'Chatbot_AIPass_Provider',
            function ($container) {
                return new Chatbot_AIPass_Provider(
                    $container->make('Chatbot_Token_Manager'),
                    $container->make('Chatbot_API_Client')
                );
            }
        );

        // AI Service Facade (primary service for AI operations)
        $this->container->singleton(
            'Chatbot_AI_Service',
            function ($container) {
                $service = new Chatbot_AI_Service(
                    $container->make('Chatbot_AIPass_Provider')
                );
                return $service;
            }
        );
    }

    /**
     * Register messaging pipeline services.
     *
     * @return void
     */
    private function register_messaging_services() {
        // Message Pipeline
        $this->container->singleton(
            'Chatbot_Message_Pipeline',
            function ($container) {
                $pipeline = new Chatbot_Message_Pipeline(
                    $container->make('Chatbot_AI_Service'),
                    $container->make('Chatbot_Message_Repository'),
                    $container->make('Chatbot_Conversation_Repository'),
                    $container->make('Chatbot_Configuration_Repository')
                );

                // Add default middleware
                $pipeline->add_middleware(new Chatbot_Validation_Middleware());
                $pipeline->add_middleware(new Chatbot_Rate_Limit_Middleware());

                return $pipeline;
            }
        );

        // Web Platform
        $this->container->singleton(
            'Chatbot_Platform_Web',
            function ($container) {
                $platform = Chatbot_Platform_Web::get_instance();
                $platform->set_pipeline($container->make('Chatbot_Message_Pipeline'));
                return $platform;
            }
        );

        // Telegram Platform (if class exists)
        $this->container->singleton(
            'Chatbot_Platform_Telegram',
            function ($container) {
                if (!class_exists('Chatbot_Platform_Telegram')) {
                    return null;
                }
                $platform = new Chatbot_Platform_Telegram();
                $platform->set_pipeline($container->make('Chatbot_Message_Pipeline'));
                return $platform;
            }
        );

        // WhatsApp Platform (if class exists)
        $this->container->singleton(
            'Chatbot_Platform_WhatsApp',
            function ($container) {
                if (!class_exists('Chatbot_Platform_WhatsApp')) {
                    return null;
                }
                $platform = new Chatbot_Platform_WhatsApp();
                $platform->set_pipeline($container->make('Chatbot_Message_Pipeline'));
                return $platform;
            }
        );
    }

    /**
     * Register legacy adapters for backward compatibility.
     *
     * These allow existing code to continue using get_instance() patterns
     * while internally using the container.
     *
     * @return void
     */
    private function register_legacy_adapters() {
        // Register the DB class as a singleton
        // This allows gradual migration from Chatbot_DB::get_instance()
        $this->container->singleton(
            'Chatbot_DB',
            function ($container) {
                return Chatbot_DB::get_instance();
            }
        );
    }

    /**
     * Boot services after all are registered.
     *
     * This is called after register() to perform any initialization
     * that requires all services to be available.
     *
     * @return void
     */
    public function boot() {
        // Inject repositories into DB class for delegation
        $db = Chatbot_DB::get_instance();

        if (method_exists($db, 'set_repositories')) {
            $db->set_repositories(
                $this->container->make('Chatbot_Conversation_Repository'),
                $this->container->make('Chatbot_Message_Repository'),
                $this->container->make('Chatbot_Configuration_Repository')
            );
        }

        // Instantiate Web Platform to register AJAX handlers
        // This must happen during init so WordPress can route AJAX requests
        if (class_exists('Chatbot_Platform_Web')) {
            $this->container->make('Chatbot_Platform_Web');
        }
    }

    /**
     * Get the container instance.
     *
     * @return Chatbot_Container
     */
    public function get_container() {
        return $this->container;
    }
}
