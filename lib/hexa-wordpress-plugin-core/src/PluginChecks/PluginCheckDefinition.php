<?php

namespace Hexa\PluginCore\PluginChecks;

final class PluginCheckDefinition {
    public string $id;
    public string $name;
    public string $plugin_file;
    public string $slug;
    public string $source;
    public string $wp_org_slug;
    public string $github_repo;
    public string $github_branch;
    public string $download_url;
    public string $download_label;
    public string $notes;
    public bool $required;
    public array $checks;

    public function __construct( array $config ) {
        $plugin_file = self::clean_plugin_file( (string) ( $config['plugin_file'] ?? $config['id'] ?? '' ) );
        $slug        = (string) ( $config['slug'] ?? dirname( $plugin_file ) );

        $this->plugin_file    = $plugin_file;
        $this->slug           = self::clean_slug( $slug );
        $this->id             = self::clean_id( (string) ( $config['id'] ?? $plugin_file ?: $this->slug ) );
        $this->name           = (string) ( $config['name'] ?? $config['label'] ?? $this->id );
        $this->source         = self::clean_source( (string) ( $config['source'] ?? 'wordpress_org' ) );
        $this->wp_org_slug    = self::clean_slug( (string) ( $config['wp_org_slug'] ?? $config['wordpress_org_slug'] ?? $this->slug ) );
        $this->github_repo    = trim( (string) ( $config['github_repo'] ?? $config['repo'] ?? '' ) );
        $this->github_branch  = trim( (string) ( $config['github_branch'] ?? $config['branch'] ?? 'main' ), '/' );
        $this->download_url   = (string) ( $config['download_url'] ?? $config['url'] ?? '' );
        $this->download_label = (string) ( $config['download_label'] ?? 'Download plugin' );
        $this->notes          = (string) ( $config['notes'] ?? $config['description'] ?? $config['additional_info'] ?? '' );
        $this->required       = (bool) ( $config['required'] ?? true );
        $this->checks         = self::normalize_checks( $config['checks'] ?? $config['approved_constraints'] ?? [] );
    }

    public static function from_array( array $config ): self {
        return new self( $config );
    }

    public function supports_ajax_install(): bool {
        if ( 'wordpress_org' === $this->source ) {
            return '' !== $this->wp_org_slug;
        }

        if ( 'github' === $this->source ) {
            return '' !== $this->github_repo;
        }

        return false;
    }

    private static function normalize_checks( mixed $checks ): array {
        if ( ! is_array( $checks ) ) {
            $checks = [];
        }

        return [
            'installed'  => (bool) ( $checks['installed'] ?? $checks['is_installed'] ?? true ),
            'active'     => (bool) ( $checks['active'] ?? $checks['is_active'] ?? true ),
            'up_to_date' => (bool) ( $checks['up_to_date'] ?? $checks['is_up_to_date'] ?? true ),
        ];
    }

    private static function clean_plugin_file( string $plugin_file ): string {
        $plugin_file = trim( str_replace( '\\', '/', $plugin_file ), '/' );

        return preg_replace( '#[^a-zA-Z0-9_./\-]#', '', $plugin_file ) ?: '';
    }

    private static function clean_slug( string $slug ): string {
        $slug = basename( trim( str_replace( '\\', '/', $slug ), '/' ) );

        return function_exists( 'sanitize_key' ) ? sanitize_key( $slug ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $slug ) );
    }

    private static function clean_id( string $value ): string {
        if ( function_exists( 'sanitize_key' ) ) {
            return sanitize_key( str_replace( '/', '-', $value ) );
        }

        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( '/', '-', $value ) ) ) ?: '';
    }

    private static function clean_source( string $source ): string {
        $source = function_exists( 'sanitize_key' ) ? sanitize_key( $source ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $source ) );

        return in_array( $source, [ 'wordpress_org', 'github', 'manual', 'pro' ], true ) ? $source : 'manual';
    }
}
