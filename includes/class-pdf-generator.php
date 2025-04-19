<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(plugin_dir_path(__FILE__) . '../vendor/autoload.php');

class PDF_Generator {
    private $post;
    private $pdf;
    private $html_content;

    public function __construct($post_id, $html_content = '') {
        $this->post = get_post($post_id);
        $this->html_content = $html_content;
        $this->pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    }

    public function generate() {
        // Set document information
        $this->pdf->SetCreator(get_bloginfo('name'));
        $this->pdf->SetAuthor(get_the_author_meta('display_name', $this->post->post_author));
        $this->pdf->SetTitle(get_the_title($this->post->ID));
        
        // Set margins
        $this->pdf->SetMargins(15, 20, 15);
        $this->pdf->SetHeaderMargin(0);
        $this->pdf->SetFooterMargin(10);
        
        // Set auto page breaks
        $this->pdf->SetAutoPageBreak(TRUE, 15);
        
        // Add a page
        $this->pdf->AddPage();
        
        // Write HTML content
        $this->pdf->writeHTML($this->html_content, true, false, true, false, '');
        
        // Output PDF
        $this->pdf->Output(get_the_title($this->post->ID) . '.pdf', 'D');
    }

    private function prepare_content($content) {
        if (empty($content)) {
            return '';
        }

        // Check if content is agenda HTML
        if (strpos($content, 'agenda-imported-content') !== false) {
            // Special handling for agenda content
            return $this->prepare_agenda_content($content);
        }

        // Process shortcodes first
        $content = do_shortcode($content);
        
        // Convert WordPress content to clean HTML
        $content = apply_filters('the_content', $content);

        // Basic cleanup of HTML
        $content = trim($content);
        
        // Force UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
        }

        // Convert HTML entities to their corresponding characters
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up and normalize HTML structure
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;
        libxml_use_internal_errors(true);

        // Add XML encoding and wrapper to ensure proper parsing
        $wrapped_content = '<?xml encoding="UTF-8">' . 
                          '<div class="pdf-content">' . $content . '</div>';
        @$dom->loadHTML($wrapped_content, 
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | 
            LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        // Normalize styles and classes while preserving important formatting
        $xpath = new DOMXPath($dom);
        
        // Process headings and add semantic structure
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $heading->setAttribute('tag', 'true');
        }

        // Ensure proper list rendering and structure
        foreach ($xpath->query('//ul|//ol') as $list) {
            $list->setAttribute('style', 'margin-left: 20px;');
            foreach ($xpath->query('.//li', $list) as $item) {
                if (trim($item->nodeValue)) {
                    $item->setAttribute('style', 'margin: 5px 0;');
                }
            }
        }

        // Process tables for better formatting
        foreach ($xpath->query('//table') as $table) {
            $table->setAttribute('style', 'width: 100%; margin: 10px 0; border-collapse: collapse;');
            foreach ($xpath->query('.//th|.//td', $table) as $cell) {
                $cell->setAttribute('style', 'border: 1px solid #ddd; padding: 8px;');
            }
        }

        // Clean up empty elements while preserving structure
        foreach ($xpath->query('//*[not(node())]') as $node) {
            if (!in_array(strtolower($node->nodeName), ['img', 'br', 'hr'])) {
                $node->parentNode->removeChild($node);
            }
        }

        // Extract only the content div
        $content_div = $xpath->query('//div[@class="pdf-content"]')->item(0);
        if ($content_div) {
            $content = $dom->saveHTML($content_div);
        } else {
            $content = $dom->saveHTML($dom->documentElement);
        }

        // Add debug logging
        error_log('PDF Print Debug - Content after processing: ' . substr($content, 0, 500));

        return $content;
    }

    private function prepare_custom_fields() {
        $custom_fields = get_post_custom($this->post->ID);
        if (empty($custom_fields)) {
            error_log('PDF Print Debug - No custom fields found');
            return '';
        }

        error_log('PDF Print Debug - Processing custom fields: ' . print_r(array_keys($custom_fields), true));

        $content = '<div class="custom-fields-section" style="margin-top: 30px; padding: 20px; background-color: #f9f9f9;">';
        $content .= '<h2 style="font-size: 24px; color: #333; margin-bottom: 20px;" tag="true">Additional Information</h2>';
        $content .= '<div class="custom-fields" style="padding: 10px;">';

        foreach ($custom_fields as $key => $values) {
            // Skip internal WordPress fields
            if (substr($key, 0, 1) === '_') {
                continue;
            }

            // Get field label
            $label = apply_filters('pdf_print_custom_field_label', $key);
            $label = ucwords(str_replace('_', ' ', $label));

            $content .= '<div class="custom-field" style="margin: 15px 0; padding: 15px; border-bottom: 1px solid #eee;">';
            $content .= '<strong style="display: block; font-size: 16px; color: #444; margin-bottom: 10px;">' . esc_html($label) . ':</strong>';

            foreach ($values as $value) {
                // Process the value based on content type
                $processed_value = $this->process_custom_field_value($value);
                if (!empty($processed_value)) {
                    $content .= '<div style="margin: 5px 0; line-height: 1.6;">' . $processed_value . '</div>';
                }
            }

            $content .= '</div>';
        }

        $content .= '</div></div>';
        error_log('PDF Print Debug - Custom fields HTML: ' . substr($content, 0, 500));
        return $content;
    }

    private function prepare_agenda_content($content) {
        // Create a new DOMDocument instance
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;
        libxml_use_internal_errors(true);

        // Load the content with proper encoding
        $wrapped_content = '<?xml encoding="UTF-8"><div class="agenda-content">' . $content . '</div>';
        @$dom->loadHTML($wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Create XPath object
        $xpath = new DOMXPath($dom);

        // Remove unnecessary meta tags and links
        foreach ($xpath->query('//meta|//link|//title|//base') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Process agenda items
        foreach ($xpath->query('//div[contains(@class, "item")]') as $item) {
            // Ensure proper spacing and structure
            $item->setAttribute('style', 'margin: 10px 0; padding: 10px 0; border-bottom: 1px solid #eee;');
        }

        // Process headings
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $heading->setAttribute('style', 'margin: 15px 0; color: #333;');
            $heading->setAttribute('tag', 'true');
        }

        // Process documents section
        foreach ($xpath->query('//div[contains(@class, "documents")]') as $docs) {
            $docs->setAttribute('style', 'margin: 10px 0; padding: 10px; background: #f9f9f9;');
        }

        // Get the processed content
        $content_div = $xpath->query('//div[@class="agenda-content"]')->item(0);
        $processed_content = $dom->saveHTML($content_div);

        libxml_clear_errors();
        return $processed_content;
    }

    private function process_custom_field_value($value) {
        // Check if value is serialized
        if (is_serialized($value)) {
            $unserialized = unserialize($value);
            if (is_array($unserialized)) {
                return implode(', ', array_map('esc_html', $unserialized));
            }
        }

        // Check if value is JSON
        $json_decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json_decoded)) {
            return implode(', ', array_map('esc_html', $json_decoded));
        }

        // Check if value is a URL to an image
        if (filter_var($value, FILTER_VALIDATE_URL) && preg_match('/\.(jpg|jpeg|png|gif)$/i', $value)) {
            return '<img src="' . esc_url($value) . '" alt="Custom field image" />';
        }

        // Default handling for text values
        return esc_html($value);
    }

    private function add_semantic_structure($content) {
        if (empty($content)) {
            return '';
        }

        // Load content into DOMDocument for processing
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Add a wrapper to preserve HTML structure
        $wrapped_content = '<div class="pdf-content">' . $content . '</div>';
        if (!@$dom->loadHTML($wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            error_log('PDF Print: Failed to load HTML content');
            return $content;
        }
        libxml_clear_errors();

        // Process images for accessibility
        $images = $dom->getElementsByTagName('img');
        foreach ($images as $img) {
            if (!$img->hasAttribute('alt')) {
                $img->setAttribute('alt', $img->getAttribute('title') ?: 'Image');
            }
        }

        // Process headings for proper hierarchy
        $xpath = new DOMXPath($dom);
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        $current_level = 1;
        foreach ($headings as $heading) {
            $level = (int)substr($heading->nodeName, 1);
            if ($level - $current_level > 1) {
                // Add intermediate heading levels if needed
                $new_heading = $dom->createElement('h' . ($current_level + 1), 'Section Heading');
                $heading->parentNode->insertBefore($new_heading, $heading);
            }
            $current_level = $level;
        }

        // Extract only the content div to remove wrapper
        $content_div = $dom->getElementsByTagName('div')->item(0);
        if ($content_div) {
            $content = '';
            foreach ($content_div->childNodes as $node) {
                $content .= $dom->saveHTML($node);
            }
        } else {
            $content = $dom->saveHTML();
        }

        // Add PDF tags for better accessibility
        $content = str_replace(
            array('<h1', '<h2', '<h3', '<h4', '<h5', '<h6'),
            array('<h1 tag="true"', '<h2 tag="true"', '<h3 tag="true"', '<h4 tag="true"', '<h5 tag="true"', '<h6 tag="true"'),
            $content
        );

        return $content;
    }

    private function get_styles() {
        return '<style>
            body {
                font-family: dejavusans, sans-serif;
                font-size: 12pt;
                line-height: 1.6;
                color: #333;
            }
            h1, h2, h3, h4, h5, h6 {
                color: #222;
                margin: 15px 0 10px;
            }
            h1 { font-size: 20pt; }
            h2 { font-size: 18pt; }
            h3 { font-size: 16pt; }
            h4 { font-size: 14pt; }
            h5 { font-size: 12pt; }
            h6 { font-size: 10pt; }
            p {
                margin: 0 0 10px;
            }
            ul, ol {
                margin: 0 0 10px 20px;
                padding: 0;
            }
            li {
                margin: 5px 0;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
            }
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            th {
                background-color: #f5f5f5;
            }
            img {
                max-width: 100%;
                height: auto;
            }
            .custom-fields-section {
                margin-top: 30px;
                padding: 20px;
                background-color: #f9f9f9;
            }
            .content-separator {
                margin: 20px 0;
            }
        </style>';
    }
}