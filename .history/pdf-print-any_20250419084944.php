<?php
/**
 * Plugin Name: PDF Printer Anything
 * Description: Adds a button to specified post types to generate accessible PDFs from page content. [pdf_print_button]
 * Version: 1.1.0
 * Author: Scott Hoenes
 * Author URI: https://scohoe.com
 * Text Domain: pdf-print
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Load Composer autoloader
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

class PDF_Generator {
    private $post_id;
    private $html_content;
    
    public function __construct($post_id, $html_content) {
        $this->post_id = $post_id;
        $this->html_content = $html_content;
    }
    
    public function generate() {
        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 20,
                'margin_bottom' => 20,
                'tempDir' => WP_CONTENT_DIR . '/uploads/pdf_temp/',
                'default_font' => 'dejavusans',
                'lang' => 'en',
                'tagged' => true,
                'displayDocTitle' => true,
                'taborder' => 'structure',
                'showWatermarkText' => false,
                'useSubstitutions' => true,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'use_kwt' => true,
                'useKerning' => true,
                'h2toc' => ['H1' => 0, 'H2' => 1, 'H3' => 2],
                'h2bookmarks' => ['H1' => 0, 'H2' => 1, 'H3' => 2]
            ]);

            // Set document metadata (required for navigation pane)
            $mpdf->SetTitle(get_the_title($this->post_id));
            $mpdf->SetAuthor(get_bloginfo('name'));
            $mpdf->SetCreator('WordPress PDF Printer Plugin');
            $mpdf->SetSubject('Agenda for ' . get_the_title($this->post_id));
            $mpdf->SetKeywords('agenda, meeting, ' . get_bloginfo('name'));
            $mpdf->SetDisplayMode('fullwidth');

            // Add accessible styles
            $stylesheet = $this->get_pdf_styles();
            $mpdf->WriteHTML($stylesheet, \Mpdf\HTMLParserMode::HEADER_CSS);

            // Add accessibility disclaimer at top (will appear below navigation)
            $disclaimer = '
                <div class="accessibility-disclaimer" style="border:1px solid #ccc; background:#f9f9f9; padding:10px; margin-bottom:20px;">
                    <p style="color:#d32f2f; font-weight:bold; margin:0 0 5px 0;">Accessibility Notice:</p>
                    <p style="margin:0; font-size:0.9em;">
                        This is an automatically generated document and may not be fully WCAG compliant. 
                        If you need assistance or a compliant version, please contact 
                        <a href="mailto:ada@archuletacounty.org" style="color:#0066cc;">ada@archuletacounty.org</a>.
                    </p>
                </div>
            ';
            
            // Process content
            $accessible_html = $this->make_html_accessible($this->html_content);
            $processed_html = $disclaimer . $this->process_html_for_pdf($accessible_html);
            
            // Write content with proper tagging
            $mpdf->WriteHTML($processed_html, \Mpdf\HTMLParserMode::HTML_BODY);
            
            // Finalize document (preserves navigation pane)
            $mpdf->AddPage();
            $mpdf->WriteHTML('<span role="doc-endnote" style="display:none"></span>');
            
            $filename = sanitize_title(get_the_title($this->post_id)) . '.pdf';
            $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
            
        } catch (\Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
            wp_die('Error generating PDF: ' . $e->getMessage());
        }
    }

    private function make_html_accessible($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html = htmlspecialchars_decode(htmlentities($html, ENT_QUOTES, 'UTF-8'));
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Ensure proper heading structure
        $this->ensure_heading_hierarchy($dom);

        // Process images with proper tagging
        $images = $dom->getElementsByTagName('img');
        foreach ($images as $img) {
            $alt = $img->getAttribute('alt');
            if (empty($alt)) {
                $img->setAttribute('alt', 'Decorative image');
                $img->setAttribute('role', 'presentation');
            } else {
                $img->setAttribute('aria-describedby', 'img-desc-' . uniqid());
                $desc = $dom->createElement('span', $alt);
                $desc->setAttribute('id', $img->getAttribute('aria-describedby'));
                $desc->setAttribute('class', 'sr-only');
                $img->parentNode->insertBefore($desc, $img);
            }
        }

        // Process links with proper nesting
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            if ($link->hasAttribute('href')) {
                $link->setAttribute('aria-label', $link->textContent ?: 'Link');
                if (!preg_match('/\(link\)$/i', $link->textContent)) {
                    $span = $dom->createElement('span', ' (link)');
                    $span->setAttribute('class', 'sr-only');
                    $link->appendChild($span);
                }
            }
        }

        // Process tables with proper tagging
        $tables = $dom->getElementsByTagName('table');
        foreach ($tables as $table) {
            $table->setAttribute('role', 'table');
            if (!$table->getElementsByTagName('caption')->length) {
                $caption = $dom->createElement('caption', 'Data Table');
                $table->insertBefore($caption, $table->firstChild);
            }
            $this->fix_table_headers($dom, $table);
        }

        return $dom->saveHTML();
    }

    private function ensure_heading_hierarchy($dom) {
        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $headings["h$i"] = $dom->getElementsByTagName("h$i");
        }
        
        $last_level = 1;
        foreach ($headings as $level => $elements) {
            $current_level = (int)substr($level, 1);
            foreach ($elements as $heading) {
                if ($current_level > $last_level + 1) {
                    for ($i = $last_level + 1; $i < $current_level; $i++) {
                        $new_heading = $dom->createElement("h$i", '');
                        $heading->parentNode->insertBefore($new_heading, $heading);
                    }
                }
                $last_level = $current_level;
            }
        }
    }

    private function fix_table_headers($dom, $table) {
        $headers = $table->getElementsByTagName('th');
        if ($headers->length === 0) {
            $firstRow = $table->getElementsByTagName('tr')->item(0);
            if ($firstRow) {
                $cells = $firstRow->getElementsByTagName('td');
                foreach ($cells as $cell) {
                    $th = $dom->createElement('th', $cell->textContent);
                    $th->setAttribute('scope', 'col');
                    $firstRow->replaceChild($th, $cell);
                }
            }
        } else {
            foreach ($headers as $header) {
                if (!$header->hasAttribute('scope')) {
                    $header->setAttribute('scope', 'col');
                }
            }
        }
        
        $rows = $table->getElementsByTagName('tr');
        foreach ($rows as $row) {
            $firstCell = $row->getElementsByTagName('td')->item(0);
            if ($firstCell && !$row->getElementsByTagName('th')->length) {
                $th = $dom->createElement('th', $firstCell->textContent);
                $th->setAttribute('scope', 'row');
                $row->replaceChild($th, $firstCell);
            }
        }
    }

    private function process_html_for_pdf($html) {
        $site_url = site_url();
        $html = str_replace('href="/', 'href="' . $site_url . '/', $html);
        $html = str_replace('src="/', 'src="' . $site_url . '/', $html);
        
        return '<div role="document">' . 
               '<h1>' . get_the_title($this->post_id) . '</h1>' . 
               '<main role="main">' . $html . '</main>' . 
               '</div>';
    }

    private function get_pdf_styles() {
        return '
            <style>
                body { 
                    font-family: DejaVu Sans, Arial, sans-serif; 
                    line-height: 1.5; 
                    color: #000000;
                }
                h1 { 
                    -pdf-outline: true;
                    -pdf-outline-level: 1;
                    color: #333; 
                    font-size: 24px; 
                    margin-bottom: 15px;
                    page-break-after: avoid;
                }
                h2 { 
                    -pdf-outline: true;
                    -pdf-outline-level: 2;
                    font-size: 20px; 
                    margin: 15px 0 10px;
                    page-break-after: avoid;
                }
                img {
                    -pdf-alt-text: attr(alt);
                    max-width: 100%;
                    height: auto;
                    page-break-inside: avoid;
                }
                a[href] {
                    -pdf-link: attr(href);
                    color: #0066cc;
                    text-decoration: underline;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    page-break-inside: avoid;
                }
                th, td {
                    border: 1px solid #ddd;
                    padding: 8px;
                    text-align: left;
                }
                th {
                    background-color: #f5f5f5;
                }
                .sr-only {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    padding: 0;
                    margin: -1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                    border: 0;
                }
                @page {
                    prince-language: en;
                    -ro-language: en;
                }
            </style>
        ';
    }
}

class PDF_Print {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_pdf_button_meta_box'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('template_redirect', array($this, 'handle_pdf_generation'));
        add_shortcode('pdf_print_button', array($this, 'render_pdf_button_shortcode'));
    }

    public function init() {
        load_plugin_textdomain('pdf-print', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Register block editor button
        if (function_exists('register_block_type')) {
            register_block_type('pdf-print/button', array(
                'render_callback' => array($this, 'render_pdf_button_block'),
                'attributes' => array(
                    'className' => array(
                        'type' => 'string',
                        'default' => 'print-area'
                    ),
                    'buttonText' => array(
                        'type' => 'string',
                        'default' => __('Generate PDF', 'pdf-print')
                    )
                )
            ));
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('PDF Print Settings', 'pdf-print'),
            __('PDF Print', 'pdf-print'),
            'manage_options',
            'pdf-print-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('pdf_print_options', 'pdf_print_post_types');
        add_settings_section(
            'pdf_print_settings',
            __('Post Types Settings', 'pdf-print'),
            array($this, 'render_settings_section'),
            'pdf-print-settings'
        );
        add_settings_field(
            'pdf_print_post_types',
            __('Enable for Post Types', 'pdf-print'),
            array($this, 'render_post_types_field'),
            'pdf-print-settings',
            'pdf_print_settings'
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('pdf_print_options');
                do_settings_sections('pdf-print-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_settings_section() {
        echo '<p>' . esc_html__('Select which post types should display the PDF print button.', 'pdf-print') . '</p>';
        echo '<h3>' . esc_html__('Shortcode Usage', 'pdf-print') . '</h3>';
        echo '<p>' . esc_html__('You can also display the PDF print button anywhere in your content using the shortcode:', 'pdf-print') . '</p>';
        echo '<code>[pdf_print_button]</code>';
        echo '<h3>' . esc_html__('Block Usage', 'pdf-print') . '</h3>';
        echo '<p>' . esc_html__('In the block editor, search for "PDF Print Button" to add a customizable button.', 'pdf-print') . '</p>';
    }

    public function render_post_types_field() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $saved_post_types = get_option('pdf_print_post_types', array('post', 'page'));

        foreach ($post_types as $post_type) {
            printf(
                '<label><input type="checkbox" name="pdf_print_post_types[]" value="%s" %s> %s</label><br>',
                esc_attr($post_type->name),
                in_array($post_type->name, $saved_post_types) ? 'checked' : '',
                esc_html($post_type->label)
            );
        }
    }

    public function add_pdf_button_meta_box() {
        $post_types = get_option('pdf_print_post_types', array('post', 'page'));
        foreach ($post_types as $post_type) {
            add_meta_box(
                'pdf-print-button',
                __('PDF Print', 'pdf-print'),
                array($this, 'render_pdf_button_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_pdf_button_meta_box($post) {
        $pdf_url = add_query_arg(
            array(
                'pdf-print' => 'true',
                'post_id' => $post->ID,
                'nonce' => wp_create_nonce('pdf-print-' . $post->ID),
                'class' => 'print-area'
            ),
            home_url()
        );
        echo '<a href="' . esc_url($pdf_url) . '" class="button button-primary" target="_blank">' .
             esc_html__('Generate PDF', 'pdf-print') . '</a>';
        echo '<p class="description">' . esc_html__('Prints content within elements with class "print-area"', 'pdf-print') . '</p>';
    }

    public function render_pdf_button_shortcode($atts = array()) {
        if (!is_singular()) {
            return '';
        }
        
        $post_id = get_the_ID();
        $post_types = get_option('pdf_print_post_types', array('post', 'page'));
        
        if (!in_array(get_post_type(), $post_types)) {
            return '';
        }
    
        $pdf_url = add_query_arg(
            array(
                'pdf-print' => 'true',
                'post_id' => $post_id,
                'nonce' => wp_create_nonce('pdf-print-' . $post_id)
            ),
            home_url()
        );
        
        return '<div class="pdf-print-button-container"><a href="' . esc_url($pdf_url) . '" class="pdf-print-button" target="_blank">' . esc_html__('Print Agenda', 'pdf-print') . '</a></div>';
    }

    public function render_pdf_button_block($attributes) {
        if (!is_singular()) {
            return '';
        }
        
        $post = get_post();
        $post_types = get_option('pdf_print_post_types', array('post', 'page'));
        
        if (!in_array(get_post_type(), $post_types)) {
            return '';
        }
        
        $class = !empty($attributes['className']) ? $attributes['className'] : 'print-area';
        $button_text = !empty($attributes['buttonText']) ? $attributes['buttonText'] : __('Generate PDF', 'pdf-print');
        
        $pdf_url = add_query_arg(
            array(
                'pdf-print' => 'true',
                'post_id' => $post->ID,
                'nonce' => wp_create_nonce('pdf-print-' . $post->ID),
                'class' => $class
            ),
            home_url()
        );
        
        return sprintf(
            '<div class="pdf-print-button-container"><a href="%s" class="pdf-print-button" target="_blank">%s</a></div>',
            esc_url($pdf_url),
            esc_html($button_text)
        );
    }

    public function enqueue_scripts() {
        if ($this->should_show_pdf_button()) {
            wp_enqueue_style(
                'pdf-print-style',
                plugins_url('css/pdf-print.css', __FILE__),
                array(),
                '1.1.0'
            );
            
            wp_enqueue_script(
                'pdf-print-script',
                plugins_url('js/pdf-print.js', __FILE__),
                array('jquery', 'wp-blocks', 'wp-element', 'wp-editor'),
                '1.1.0',
                true
            );
            
            wp_localize_script('pdf-print-script', 'pdfPrintVars', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pdf_print_nonce')
            ));
        }
    }

    public function enqueue_admin_scripts($hook) {
        if ('settings_page_pdf-print-settings' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'pdf-print-admin-style',
            plugins_url('css/pdf-print-admin.css', __FILE__),
            array(),
            '1.1.0'
        );
    }

    private function should_show_pdf_button() {
        if (!is_singular()) {
            return false;
        }
        $post_types = get_option('pdf_print_post_types', array('post', 'page'));
        return in_array(get_post_type(), $post_types);
    }

    public function handle_pdf_generation() {
        if (!isset($_GET['pdf-print']) || $_GET['pdf-print'] !== 'true' || !isset($_GET['post_id']) || !isset($_GET['nonce'])) {
            return;
        }
        
        error_log('PDF Print: Starting PDF generation process');

        $post_id = intval($_GET['post_id']);
        if (!wp_verify_nonce($_GET['nonce'], 'pdf-print-' . $post_id)) {
            error_log('PDF Print: Security check failed');
            wp_die(__('Security check failed', 'pdf-print'));
        }

        // Get the post and setup data
        $post = get_post($post_id);
        setup_postdata($post);

        // Check if this is an agenda event
        $is_agenda_event = carbon_get_post_meta($post_id, 'agenda_sections') || 
                          carbon_get_post_meta($post_id, 'imported_agenda_html');
        
        error_log('PDF Print: Is agenda event: ' . ($is_agenda_event ? 'Yes' : 'No'));
        error_log('PDF Print: Post ID: ' . $post_id);
        error_log('PDF Print: Post type: ' . get_post_type($post_id));

        if ($is_agenda_event) {
            // Directly call the shortcode function to get clean HTML
            $html_content = display_agenda_items_shortcode(array());
            error_log('PDF Print: Using agenda shortcode content, length: ' . strlen($html_content));
            
            // If there's imported HTML, use that as the primary content
            $imported_html = carbon_get_post_meta($post_id, 'imported_agenda_html');
            if (!empty($imported_html)) {
                $html_content = $imported_html;
                error_log('PDF Print: Using imported agenda HTML, length: ' . strlen($html_content));
            }
        } else {
            // Regular post handling
            ob_start();
            the_content();
            $html_content = ob_get_clean();
            error_log('PDF Print: Regular post content length: ' . strlen($html_content));
            
            // Extract content from print-area
            $html_content = $this->extract_print_area($html_content);
            error_log('PDF Print: Content after print-area extraction, length: ' . strlen($html_content));
        }

        wp_reset_postdata();

        // Generate the PDF
        try {
            $generator = new PDF_Generator($post_id, $html_content);
            $generator->generate();
        } catch (Exception $e) {
            error_log('PDF Print: Error generating PDF: ' . $e->getMessage());
            wp_die('Error generating PDF: ' . $e->getMessage());
        }
        exit;
    }
    
    private function get_rendered_post_content($post_id) {
        // First try using the REST API
        $response = wp_remote_get(rest_url("wp/v2/posts/{$post_id}?context=edit"));
        
        if (!is_wp_error($response) && $response['response']['code'] === 200) {
            $data = json_decode($response['body'], true);
            if (isset($data['content']['rendered'])) {
                return $data['content']['rendered'];
            }
        }
        
        // Fallback to direct post content
        $post = get_post($post_id);
        setup_postdata($post);
        ob_start();
        the_content();
        $content = ob_get_clean();
        wp_reset_postdata();
        
        return $content;
    }
    
    private function extract_print_area($content) {
        error_log('PDF Print: Starting content extraction');
        error_log('PDF Print: Content length before processing: ' . strlen($content));
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $errors = libxml_get_errors();
        if (!empty($errors)) {
            error_log('PDF Print: DOM loading errors: ' . count($errors));
            foreach ($errors as $error) {
                error_log('PDF Print: DOM error: ' . $error->message);
            }
        }
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Try class selector first
        $print_areas = $xpath->query("//*[contains(@class, 'print-area')]");
        error_log('PDF Print: Found ' . $print_areas->length . ' elements with class print-area');
        
        // If no class found, try ID
        if ($print_areas->length === 0) {
            $print_areas = $xpath->query("//*[@id='print-area']");
            error_log('PDF Print: Found ' . $print_areas->length . ' elements with ID print-area');
        }
        
        $html_content = '';
        foreach ($print_areas as $area) {
            $html_content .= $dom->saveHTML($area);
        }
        
        error_log('PDF Print: Content length after processing: ' . strlen($html_content));
        
        // If no print area found, log and return full content
        if (empty($html_content)) {
            error_log('PDF Print: No print-area found, using full content');
            return $content;
        }
        
        return $html_content;
    }
}

// Initialize the plugin
PDF_Print::get_instance();
