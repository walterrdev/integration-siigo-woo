<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}


class Integration_Siigo_WC_Plugin
{
    /**
     * Absolute plugin path.
     *
     * @var string
     */
    public string $plugin_path;
    /**
     * Absolute plugin URL.
     *
     * @var string
     */
    public string $plugin_url;
    /**
     * assets plugin.
     *
     * @var string
     */
    public string $assets;
    /**
     * Absolute path to plugin includes dir.
     *
     * @var string
     */
    public string $includes_path;
    /**
     * Absolute path to plugin lib dir
     *
     * @var string
     */
    public string $lib_path;
    /**
     * @var bool
     */
    private bool $bootstrapped = false;

    public function __construct(
        protected $file,
        protected $version
    )
    {
        $this->plugin_path = trailingslashit(plugin_dir_path($this->file));
        $this->plugin_url = trailingslashit(plugin_dir_url($this->file));
        $this->assets = $this->plugin_url . trailingslashit('assets');
        $this->includes_path = $this->plugin_path . trailingslashit('includes');
        $this->lib_path = $this->plugin_path . trailingslashit('lib');
    }

    public function run_siigo(): void
    {
        try {
            if ($this->bootstrapped) {
                throw new Exception('Integration Siigo Woocommerce can only be called once');
            }
            $this->_run();
            $this->bootstrapped = true;
        } catch (Exception $e) {
            if (is_admin() && !defined('DOING_AJAX')) {
                add_action('admin_notices', function () use ($e) {
                    integration_siigo_wc_smp_notices($e->getMessage());
                });
            }
        }
    }

    private function _run(): void
    {
        if (!class_exists('\Saulmoralespa\Siigo\Client'))
            require_once($this->lib_path . 'vendor/autoload.php');

        if (!class_exists('WC_Siigo_Integration')) {
            require_once($this->includes_path . 'class-siigo-integration-wc.php');
            add_filter('woocommerce_integrations', array($this, 'add_integration'));
        }

        if (!class_exists('Integration_Siigo_WC')) {
            require_once($this->includes_path . 'class-integration-siigo-wc.php');
        }

        require_once ($this->lib_path . 'plugin-update-checker/plugin-update-checker.php');

        $myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/saulmoralespa/integration-siigo-woo',
            $this->file
        );

        $myUpdateChecker->setBranch('main');
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();

        add_filter('plugin_action_links_' . plugin_basename($this->file), array($this, 'plugin_action_links'));
        add_filter('bulk_actions-edit-product', array($this, 'sync_bulk_actions'), 20 );
        add_filter('handle_bulk_actions-edit-product', array($this, 'sync_bulk_action_edit_product'), 10, 3);
        add_filter('manage_edit-shop_order_columns', array($this, 'create_invoice_column'), 20);
        add_filter('woocommerce_checkout_fields', array($this, 'document_woocommerce_fields'));

        add_action('woocommerce_checkout_process', array($this, 'very_nit_validation'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'custom_checkout_fields_update_order_meta'));
        add_action('woocommerce_init', array($this, 'register_additional_checkout_fields'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts_admin') );
        add_action('woocommerce_order_status_changed', array('Integration_Siigo_WC', 'generate_invoice'), 10, 3);
        add_action('integration_siigo_wc_smp_schedule', array('Integration_Siigo_WC', 'sync_products'));
        add_action('wp_ajax_integration_siigo_sync_products', array($this, 'ajax_integration_siigo_sync_products'));

        add_action(
            'woocommerce_set_additional_field_value',
            function ( $key, $value, $group, $wc_object ) {

                if ('document/dni' !== $key ) {
                    return;
                }

                $type_document_key = "_wc_$group/document/type_document";
                $dni_key = "_wc_$group/document/dni";
                $type_document = $wc_object->get_meta($type_document_key);
                $dni = $wc_object->get_meta($dni_key);

                if($type_document === 'NIT'){
                    $dv = Integration_Siigo_WC::calculateDv($dni);
                    $dni = "$dni-$dv";
                }

                $wc_object->update_meta_data($dni_key, $dni, true);
                $wc_object->save();
            },
            10,
            4
        );
    }

    public function plugin_action_links($links): array
    {
        $links[] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=integration&section=wc_siigo_integration') . '">' . 'Configuraciones' . '</a>';
        $links[] = '<a target="_blank" href="https://shop.saulmoralespa.com/integration-siigo-woocommerce/">' . 'Documentación' . '</a>';
        return $links;
    }

    public function sync_bulk_actions(array $bulk_actions): array
    {
        $settings = get_option('woocommerce_wc_siigo_integration_settings');

        if((isset($settings['username']) &&
                $settings['access_key']) ||
            (isset($settings['sandbox_username']) &&
                $settings['sandbox_access_key']) &&
            $settings['enabled'] === 'yes'
        ){
            $bulk_actions['integration_siigo_sync'] = 'Sincronizar productos Siigo';
        }
        return $bulk_actions;
    }

    public function sync_bulk_action_edit_product($redirect_to, $action, array $post_ids) :string
    {
        if ($action !== 'integration_siigo_sync') return $redirect_to;

        Integration_Siigo_WC::sync_products_to_siigo($post_ids);

        return $redirect_to;
    }

    public function add_integration($integrations): array
    {
        $integrations[] = 'WC_Siigo_Integration';
        return $integrations;
    }

    public function log($message): void
    {
        if (is_array($message) || is_object($message))
            $message = print_r($message, true);
        $logger = new WC_Logger();
        $logger->add('integration-siigo', $message);
    }

    public function enqueue_scripts(): void
    {
        if ( is_checkout() ) {
            wp_enqueue_script( 'integration-siigo-field-dni', $this->plugin_url . 'assets/js/field-dni-checkout.js', array( 'jquery' ), $this->version, true );
        }
    }

    public function enqueue_scripts_admin($hook): void
    {
        if($hook === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === 'wc_siigo_integration'){
            wp_enqueue_script( 'integration-siigo-sweet-alert', $this->assets. 'js/sweetalert2.min.js', array( 'jquery' ), $this->version, true );
            wp_enqueue_script( 'integration-siigo', $this->assets. 'js/integration-siigo.js', array( 'jquery' ), $this->version, true );
        }
    }

    public function ajax_integration_siigo_sync_products()
    {
        if ( ! wp_verify_nonce(  $_REQUEST['nonce'], 'integration_siigo_sync_products' ) )
            return;

        Integration_Siigo_WC::sync_products();
        wp_send_json(['status' => true]);
    }

    public function create_invoice_column($columns)
    {
        $columns['create_invoice_siigo'] = 'Factura Siigo';
        return $columns;
    }

    public function register_additional_checkout_fields(): void
    {
        woocommerce_register_additional_checkout_field(
            array(
                'id'       => 'document/type_document',
                'label'    => 'Tipo de documento',
                'location' => 'address',
                'type'     => 'select',
                'required' => true,
                'options'  => [
                    [
                        'value' => 'CC',
                        'label' => 'Cédula de ciudadanía'
                    ],
                    [
                        'value' => 'NIT',
                        'label' => '(NIT) Número de indentificación tributaria'
                    ]
                ]
            )
        );
        woocommerce_register_additional_checkout_field(
            array(
                'id'            => 'document/dni',
                'label'         => 'Número de documento',
                'optionalLabel' => '1055666777',
                'location'      => 'address',
                'required'      => true,
                'attributes'    => array(
                    'autocomplete'     => 'billing_dni',
                    'aria-describedby' => 'some-element',
                    'aria-label'       => 'Número de documento',
                    'pattern'          => '[0-9]{5,12}'
                )
            ),
        );
    }

    public function document_woocommerce_fields(array $fields): array
    {
        $fields['billing']['billing_type_document'] = array(
            'label'       => __('Tipo de documento'),
            'placeholder' => _x('', 'placeholder'),
            'required'    => true,
            'clear'       => false,
            'type'        => 'select',
            'default' => 'CC',
            'options'     => array(
                'CC' => __('Cédula de ciudadanía' ),
                'NIT' => __('(NIT) Número de indentificación tributaria')
            )
        );

        $fields['billing']['billing_dni'] = array(
            'label' => __('Número de documento'),
            'placeholder' => _x('', 'placeholder'),
            'required' => true,
            'clear' => false,
            'type' => 'number',
            'custom_attributes' => array(
                'minlength' => 5
            ),
            'class' => array('my-css')
        );


        $fields['shipping']['shipping_type_document'] = array(
            'label'       => __('Tipo de documento'),
            'placeholder' => _x('', 'placeholder'),
            'required'    => true,
            'clear'       => false,
            'type'        => 'select',
            'default' => 'CC',
            'options'     => array(
                'CC' => __('Cédula de ciudadanía' ),
                'NIT' => __('(NIT) Número de indentificación tributaria')
            )
        );

        $fields['shipping']['shipping_dni'] = array(
            'label' => __('Número de documento'),
            'placeholder' => _x('', 'placeholder'),
            'required' => true,
            'clear'    => false,
            'type' => 'number',
            'custom_attributes' => array(
                'minlength' => 5
            ),
            'class' => array('my-css')
        );

        return $fields;
    }

    public function very_nit_validation(): void
    {
        $billing_type_document = sanitize_text_field($_POST['billing_type_document']);
        $billing_dni = sanitize_text_field($_POST['billing_dni']);
        $shipping_type_document = sanitize_text_field($_POST['shipping_type_document']);
        $shipping_dni = sanitize_text_field($_POST['shipping_dni']);

        if(($billing_type_document === 'NIT' && $billing_dni && strlen($billing_dni) !== 9) ||
            ($shipping_type_document === 'NIT' && $shipping_dni && strlen($shipping_dni) !== 9)){
            wc_add_notice( __( '<p>Ingrese un NIT válido sin el DV</p>' ), 'error' );
        }
    }

    public function custom_checkout_fields_update_order_meta($order_id): void
    {
        $billing_type_document = sanitize_text_field($_POST['billing_type_document']);
        $billing_dni = sanitize_text_field($_POST['billing_dni']);
        $shipping_type_document = sanitize_text_field($_POST['shipping_type_document']);
        $shipping_dni = sanitize_text_field($_POST['shipping_dni']);

        if($billing_type_document === 'NIT' && $billing_dni){
            $dv = Integration_Siigo_WC::calculateDv($billing_dni);
            $nit = "$billing_dni-$dv";
            update_post_meta( $order_id, '_billing_dni', $nit );
        }
        if($shipping_type_document === 'NIT' && $shipping_dni){
            $dv = Integration_Siigo_WC::calculateDv($shipping_dni);
            $nit = "$shipping_dni-$dv";
            update_post_meta( $order_id, '_shipping_dni', $nit );
        }
    }
}