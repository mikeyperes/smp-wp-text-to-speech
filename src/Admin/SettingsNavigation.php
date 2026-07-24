<?php

namespace Smp\TextToSpeech\Admin;

use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;

final class SettingsNavigation {
    private const TABS = [
        "overview"   => "Dashboard",
        "api"        => "API Settings",
        "features"   => "Features",
        "display"    => "Display",
        "shortcodes" => "Shortcodes",
        "schema"     => "Schema",
    ];

    private const GROUPS = [
        [ "label" => "Overview", "tabs" => [ "overview" ] ],
        [ "label" => "Configuration", "tabs" => [ "api", "features", "display" ] ],
        [ "label" => "Advanced", "tabs" => [ "shortcodes", "schema", "hexa_core" ] ],
    ];

    /**
     * @return array<string,string>
     */
    public function tabs(): array {
        $filtered = apply_filters( "smp_tts_dashboard_tabs", self::TABS );
        $tabs = [];

        foreach ( is_array( $filtered ) ? $filtered : self::TABS as $id => $label ) {
            $id = sanitize_key( (string) $id );
            if ( "" !== $id ) {
                $tabs[ $id ] = (string) $label;
            }
        }

        return $tabs;
    }

    /**
     * @return array<int,array{label:string,tabs:array<int,string>}>
     */
    public function groups(): array {
        $tabs = $this->tabs();
        $groups = [];
        $assigned = [];

        foreach ( self::GROUPS as $group ) {
            $group_tabs = [];
            foreach ( $group["tabs"] as $id ) {
                if ( isset( $tabs[ $id ] ) && ! isset( $assigned[ $id ] ) ) {
                    $group_tabs[] = $id;
                    $assigned[ $id ] = true;
                }
            }
            if ( [] !== $group_tabs ) {
                $groups[] = [ "label" => $group["label"], "tabs" => $group_tabs ];
            }
        }

        $leftover = [];
        foreach ( array_keys( $tabs ) as $id ) {
            if ( ! isset( $assigned[ $id ] ) ) {
                $leftover[] = $id;
            }
        }
        if ( [] !== $leftover ) {
            $groups[] = [ "label" => "More", "tabs" => $leftover ];
        }

        return apply_filters( "smp_tts_dashboard_tab_groups", $groups );
    }

    public function registry( callable $renderer, string $capability = "manage_options" ): TabRegistry {
        $registry = new TabRegistry();

        foreach ( $this->tabs() as $id => $label ) {
            $registry->add(
                new TabDefinition(
                    $id,
                    $label,
                    static function () use ( $renderer, $id ): void {
                        $renderer( $id );
                    },
                    $capability
                )
            );
        }

        return $registry;
    }

    public function resolve( string $requested ): string {
        $requested = sanitize_key( $requested );
        if ( "hexa-core" === $requested ) {
            $requested = "hexa_core";
        }

        return isset( $this->tabs()[ $requested ] ) ? $requested : "overview";
    }
}
