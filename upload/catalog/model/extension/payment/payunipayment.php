<?php

class ModelExtensionPaymentPayunipayment extends Model {

    private $error = array();
    private $prefix;

    public function __construct($registry) {
        parent::__construct($registry);
        $this->prefix = (version_compare(VERSION, '3.0', '>=')) ? 'payment_' : '';
    }


    public function getMethod($address, $total) {
        $this->load->language('extension/payment/payunipayment');

        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get($this->prefix . 'payunipayment_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

        if ($this->config->get($this->prefix . 'payunipayment_total') > 0 && $this->config->get($this->prefix . 'payunipayment_total') > $total) {
            $status = false;
        } elseif (!$this->config->get($this->prefix . 'payunipayment_geo_zone_id')) {
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        $method_data = array();

        if ($status) {
            $title = $this->config->get($this->prefix . 'payunipayment_title');
            // $terms = $this->config->get($this->prefix . 'payunipayment_terms');
            $method_data = array(
                'code'       => 'payunipayment',
                'title'      => isset($title[$this->config->get('config_language_id')]) ? $title[$this->config->get('config_language_id')] : $this->language->get('text_title'),
                // 'terms'      => isset($terms[$this->config->get('config_language_id')]) ? $terms[$this->config->get('config_language_id')] : $this->language->get('text_terms'),
                'sort_order' => $this->config->get($this->prefix . 'payunipayment_sort_order')
            );
        }

        return $method_data;
    }
}
