<?php

namespace Hexa\PluginCore\SnippetRegistry;

use Hexa\PluginCore\WpAdminComponents\CoreUi;

final class SnippetRenderer {
    public function render( SnippetRegistry|array $snippets, array $args = [] ): string {
        $registry = $snippets instanceof SnippetRegistry ? $snippets : ( new SnippetRegistry() )->add_many( $snippets );
        $summary  = $registry->summary();

        $title       = isset( $args["title"] ) ? (string) $args["title"] : "Snippets";
        $description = isset( $args["description"] ) ? (string) $args["description"] : "Enable, document, and test plugin snippets from one generic Hexa Core view.";
        $ajax_url    = isset( $args["ajax_url"] ) ? (string) $args["ajax_url"] : ( function_exists( "admin_url" ) ? admin_url( "admin-ajax.php" ) : "" );
        $toggle      = isset( $args["toggle_action"] ) ? sanitize_key( (string) $args["toggle_action"] ) : "";
        $test        = isset( $args["test_action"] ) ? sanitize_key( (string) $args["test_action"] ) : "";
        $nonce       = isset( $args["nonce"] ) ? (string) $args["nonce"] : "";
        $nonce_field = isset( $args["nonce_field"] ) ? sanitize_key( (string) $args["nonce_field"] ) : "nonce";
        $categories  = isset( $args["categories"] ) && is_array( $args["categories"] ) ? $args["categories"] : [];
        $root_id     = isset( $args["root_id"] ) ? sanitize_html_class( (string) $args["root_id"] ) : "hpc-snippet-registry";

        ob_start();
        CoreUi::render_assets();
        ?>
        <div id="<?php echo esc_attr( $root_id ); ?>" class="hpc-ui hpc-snippets" data-hpc-snippets data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-toggle-action="<?php echo esc_attr( $toggle ); ?>" data-test-action="<?php echo esc_attr( $test ); ?>" data-nonce-field="<?php echo esc_attr( $nonce_field ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <?php echo $this->styles_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <section class="hpc-card hpc-snippets-intro">
                <div>
                    <h3><?php echo esc_html( $title ); ?></h3>
                    <p><?php echo esc_html( $description ); ?></p>
                </div>
                <div class="hpc-snippets-summary">
                    <?php echo CoreUi::pill( (string) $summary["total"] . " snippets", "dark" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo CoreUi::pill( (string) $summary["enabled"] . " active", "success" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo CoreUi::pill( (string) $summary["disabled"] . " inactive", "warning" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </section>

            <?php foreach ( $registry->grouped() as $category_id => $definitions ) : ?>
                <?php $category = $this->category_config( (string) $category_id, $categories ); ?>
                <section class="hpc-snippet-category" data-snippet-category="<?php echo esc_attr( (string) $category_id ); ?>">
                    <div class="hpc-snippet-category-head">
                        <div>
                            <h3><?php echo esc_html( $category["label"] ); ?></h3>
                            <?php if ( "" !== $category["description"] ) : ?><p><?php echo esc_html( $category["description"] ); ?></p><?php endif; ?>
                        </div>
                        <?php echo CoreUi::pill( (string) count( $definitions ) . " items", "dark" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <div class="hpc-snippet-list">
                        <?php foreach ( $definitions as $definition ) : ?>
                            <?php echo $this->render_definition( $registry, $definition, "" !== $toggle, "" !== $test ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_definition( SnippetRegistry $registry, SnippetDefinition $definition, bool $can_toggle, bool $can_test ): string {
        $enabled = $registry->is_enabled( $definition );
        $test    = $registry->test( $definition->id );
        $status  = $this->test_tone( (string) $test["status"] );

        ob_start();
        ?>
        <article class="hpc-snippet-row<?php echo $definition->deprecated ? " is-deprecated" : ""; ?>" data-snippet-id="<?php echo esc_attr( $definition->id ); ?>">
            <div class="hpc-snippet-row-main">
                <div class="hpc-snippet-titleline">
                    <span class="hpc-code"><?php echo esc_html( $definition->id ); ?></span>
                    <h4><?php echo esc_html( $definition->name ); ?></h4>
                    <?php echo CoreUi::pill( $enabled ? "Active" : "Inactive", $enabled ? "success" : "warning" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo CoreUi::pill( "Test " . ucfirst( (string) $test["status"] ), $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php if ( $definition->deprecated ) : ?><?php echo CoreUi::pill( "Deprecated", "danger" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
                    <?php if ( $definition->scope_admin_only ) : ?><?php echo CoreUi::pill( "Admin only", "dark" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php endif; ?>
                </div>
                <?php if ( "" !== $definition->description ) : ?><p><?php echo wp_kses_post( $definition->description ); ?></p><?php endif; ?>
            </div>

            <div class="hpc-snippet-row-actions">
                <label class="hpc-field-switch">
                    <input type="checkbox" class="hpc-snippet-toggle" data-snippet-toggle data-snippet-id="<?php echo esc_attr( $definition->id ); ?>" <?php checked( $enabled ); ?> <?php disabled( ! $can_toggle ); ?>>
                    <span></span>
                    <strong data-snippet-state><?php echo esc_html( $enabled ? "Enabled" : "Disabled" ); ?></strong>
                </label>
                <button type="button" class="hpc-button secondary" data-snippet-test data-snippet-id="<?php echo esc_attr( $definition->id ); ?>" <?php disabled( ! $can_test ); ?>>Test</button>
                <div class="hpc-snippet-save" data-snippet-save aria-live="polite"></div>
            </div>

            <div class="hpc-snippet-components">
                <?php echo $this->description_component( $definition ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo $this->testing_component( $test ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo $this->list_component( "Snippets", $definition->snippets, "No snippet internals registered." ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo $this->list_component( "Shortcodes", $definition->shortcodes, "No related shortcodes registered." ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo $this->readme_component( $definition ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private function description_component( SnippetDefinition $definition ): string {
        $body = "" !== $definition->description ? "<p>" . wp_kses_post( $definition->description ) . "</p>" : "<p>No description registered.</p>";
        if ( "" !== $definition->info ) {
            $body .= "<div class=\"hpc-snippet-info\">" . wp_kses_post( $definition->info ) . "</div>";
        }

        return CoreUi::collapsible( [ "title" => "Description", "body_html" => $body, "open" => false ] );
    }

    private function testing_component( array $test ): string {
        $rules = isset( $test["rules"] ) && is_array( $test["rules"] ) ? $test["rules"] : [];
        $body = "<p>" . esc_html( (string) ( $test["message"] ?? "" ) ) . "</p>";
        $body .= "<div class=\"hpc-snippet-test-rules\" data-snippet-test-rules>";
        foreach ( $rules as $rule ) {
            $passed = ! empty( $rule["passed"] );
            $body .= "<div class=\"hpc-snippet-test-rule " . ( $passed ? "is-pass" : "is-fail" ) . "\">";
            $body .= "<strong>" . esc_html( (string) ( $rule["label"] ?? "Rule" ) ) . "</strong>";
            $body .= CoreUi::pill( $passed ? "Pass" : "Fail", $passed ? "success" : "danger" );
            $body .= ! empty( $rule["required"] ) ? CoreUi::pill( "Required", "dark" ) : CoreUi::pill( "Optional", "warning" );
            if ( ! empty( $rule["description"] ) ) {
                $body .= "<p>" . esc_html( (string) $rule["description"] ) . "</p>";
            }
            if ( ! empty( $rule["message"] ) ) {
                $body .= "<p class=\"hpc-small\">" . esc_html( (string) $rule["message"] ) . "</p>";
            }
            $body .= "</div>";
        }
        $body .= "</div>";

        return CoreUi::collapsible( [ "title" => "Testing", "body_html" => $body, "open" => false ] );
    }

    private function list_component( string $title, array $items, string $empty ): string {
        if ( empty( $items ) ) {
            return CoreUi::collapsible( [ "title" => $title, "body_html" => "<p>" . esc_html( $empty ) . "</p>", "open" => false ] );
        }

        $body = "<ul class=\"hpc-list hpc-snippet-meta-list\">";
        foreach ( $items as $item ) {
            $label = isset( $item["label"] ) && is_scalar( $item["label"] ) ? (string) $item["label"] : "Item";
            $value = isset( $item["value"] ) && is_scalar( $item["value"] ) ? (string) $item["value"] : ( isset( $item["tag"] ) && is_scalar( $item["tag"] ) ? "[" . (string) $item["tag"] . "]" : "" );
            $desc  = isset( $item["description"] ) && is_scalar( $item["description"] ) ? (string) $item["description"] : "";
            $body .= "<li><strong>" . esc_html( $label ) . "</strong>";
            if ( "" !== $value ) {
                $body .= " <span class=\"hpc-code\">" . esc_html( $value ) . "</span>";
            }
            if ( "" !== $desc ) {
                $body .= "<p>" . esc_html( $desc ) . "</p>";
            }
            $body .= "</li>";
        }
        $body .= "</ul>";

        return CoreUi::collapsible( [ "title" => $title, "body_html" => $body, "open" => false ] );
    }

    private function readme_component( SnippetDefinition $definition ): string {
        $readme = $definition->readme;
        if ( "" === $readme ) {
            $readme = $definition->name . "\n\n" . ( "" !== $definition->description ? wp_strip_all_tags( $definition->description ) : "No readme has been registered for this snippet." );
        }

        return CoreUi::collapsible( [ "title" => "Basic README", "body_html" => "<pre class=\"hpc-readme\">" . esc_html( $readme ) . "</pre>", "open" => false ] );
    }

    private function category_config( string $category_id, array $categories ): array {
        $config = isset( $categories[ $category_id ] ) && is_array( $categories[ $category_id ] ) ? $categories[ $category_id ] : [];

        return [
            "label"       => isset( $config["label"] ) ? (string) $config["label"] : ucwords( str_replace( [ "-", "_" ], " ", $category_id ) ),
            "description" => isset( $config["description"] ) ? (string) $config["description"] : "",
        ];
    }

    private function test_tone( string $status ): string {
        return match ( $status ) {
            "pass" => "success",
            "warn" => "warning",
            default => "danger",
        };
    }

    private function styles_once(): string {
        static $rendered = false;
        if ( $rendered ) {
            return "";
        }
        $rendered = true;

        return <<<'HTML'
<style>.hpc-snippets-intro{align-items:flex-start;display:flex;gap:18px;justify-content:space-between;margin:0 0 14px}.hpc-snippets-intro h3{font-size:20px}.hpc-snippets-summary{align-items:center;display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}.hpc-snippet-category{background:#fff;border:1px solid var(--hpc-line);border-radius:8px;margin:0 0 14px;overflow:hidden}.hpc-snippet-category-head{align-items:flex-start;background:#f8fbff;border-bottom:1px solid var(--hpc-line);display:flex;gap:12px;justify-content:space-between;padding:15px 16px}.hpc-snippet-category-head h3{font-size:17px;margin:0}.hpc-snippet-category-head p{color:var(--hpc-muted);font-size:13px;margin:5px 0 0}.hpc-snippet-list{display:grid;gap:0}.hpc-snippet-row{border-bottom:1px solid #edf1f6;display:grid;gap:14px;grid-template-columns:minmax(0,1fr) auto;padding:16px}.hpc-snippet-row:last-child{border-bottom:0}.hpc-snippet-row.is-deprecated{background:#fff8f8}.hpc-snippet-titleline{align-items:center;display:flex;flex-wrap:wrap;gap:8px;margin:0 0 8px}.hpc-snippet-titleline h4{font-size:16px;margin:0}.hpc-snippet-row-main p{color:#3f4d63;line-height:1.55;margin:0}.hpc-snippet-row-actions{align-items:flex-end;display:flex;flex-direction:column;gap:9px;min-width:170px}.hpc-field-switch{align-items:center;display:inline-flex;gap:10px}.hpc-field-switch input{opacity:0;position:absolute}.hpc-field-switch span{background:#cbd5e1;border-radius:999px;display:inline-block;height:24px;position:relative;width:44px}.hpc-field-switch span:before{background:#fff;border-radius:999px;content:"";height:18px;left:3px;position:absolute;top:3px;transition:.18s;width:18px}.hpc-field-switch input:checked+span{background:var(--hpc-green)}.hpc-field-switch input:checked+span:before{transform:translateX(20px)}.hpc-snippet-save{color:var(--hpc-muted);font-size:12px;min-height:18px}.hpc-snippet-components{display:grid;gap:10px;grid-column:1 / -1;grid-template-columns:repeat(2,minmax(0,1fr))}.hpc-snippet-info{background:#f8fafc;border:1px solid #e3e8f0;border-radius:8px;margin-top:10px;padding:10px}.hpc-snippet-test-rules{display:grid;gap:8px}.hpc-snippet-test-rule{border:1px solid var(--hpc-line);border-left:4px solid #cbd5e1;border-radius:8px;padding:10px}.hpc-snippet-test-rule.is-pass{border-left-color:var(--hpc-green)}.hpc-snippet-test-rule.is-fail{border-left-color:var(--hpc-red)}.hpc-snippet-test-rule strong{display:inline-block;margin:0 8px 6px 0}.hpc-snippet-test-rule p{margin:4px 0 0}.hpc-snippet-meta-list p{color:var(--hpc-muted);font-size:12px;margin:4px 0 0}@media(max-width:900px){.hpc-snippets-intro,.hpc-snippet-category-head,.hpc-snippet-row{grid-template-columns:1fr}.hpc-snippet-row-actions{align-items:flex-start}.hpc-snippet-components{grid-template-columns:1fr}}</style>
<script>(function(){if(window.hexaSnippetRegistryReady)return;window.hexaSnippetRegistryReady=true;function setSave(row,text){var el=row?row.querySelector("[data-snippet-save]"):null;if(el)el.textContent=text||""}function post(root,body){return fetch(root.dataset.ajaxUrl||window.ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(response){return response.json()})}document.addEventListener("change",function(event){var input=event.target.closest("[data-snippet-toggle]");if(!input)return;var root=input.closest("[data-hpc-snippets]");var row=input.closest(".hpc-snippet-row");if(!root||!root.dataset.toggleAction)return;var body=new URLSearchParams();body.set("action",root.dataset.toggleAction);body.set(root.dataset.nonceField||"nonce",root.dataset.nonce||"");body.set("snippet_id",input.getAttribute("data-snippet-id")||"");body.set("enable",input.checked?"1":"0");input.disabled=true;setSave(row,"Saving...");post(root,body).then(function(payload){if(!payload||!payload.success)throw new Error((payload&&payload.data&&(payload.data.message||payload.data.error))||"Save failed");var state=row?row.querySelector("[data-snippet-state]"):null;if(state)state.textContent=input.checked?"Enabled":"Disabled";setSave(row,payload.data.message||"Saved.");}).catch(function(error){input.checked=!input.checked;setSave(row,"Error: "+(error&&error.message?error.message:"Save failed"));}).finally(function(){input.disabled=false})});document.addEventListener("click",function(event){var button=event.target.closest("[data-snippet-test]");if(!button)return;var root=button.closest("[data-hpc-snippets]");var row=button.closest(".hpc-snippet-row");if(!root||!root.dataset.testAction)return;var body=new URLSearchParams();body.set("action",root.dataset.testAction);body.set(root.dataset.nonceField||"nonce",root.dataset.nonce||"");body.set("snippet_id",button.getAttribute("data-snippet-id")||"");button.disabled=true;setSave(row,"Testing...");post(root,body).then(function(payload){if(!payload||!payload.success)throw new Error((payload&&payload.data&&(payload.data.message||payload.data.error))||"Test failed");setSave(row,payload.data.message||"Test complete.");}).catch(function(error){setSave(row,"Error: "+(error&&error.message?error.message:"Test failed"));}).finally(function(){button.disabled=false})})})();</script>
HTML;
    }
}
