<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CustomClientPriceViewer extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'customclientpriceviewer';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Tu Nombre';
        $this->need_instance = 1;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Visor de Precio de Cliente Personalizado');
        $this->description = $this->l('Muestra el precio de un cliente específico en la página de producto, visible solo para grupos seleccionados.');

        $this->ps_versions_compliancy = array('min' => '8.1.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        Configuration::updateValue('CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER', null);
        Configuration::updateValue('CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS', serialize([]));

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('displayProductPriceBlock');
    }

    public function uninstall()
    {
        Configuration::deleteByName('CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER');
        Configuration::deleteByName('CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS');
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';
        if (((bool)Tools::isSubmit('submitCustomClientPriceViewerModule')) == true) {
            $output .= $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCustomClientPriceViewerModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        $groups = Group::getGroups($this->context->language->id);
        $visible_groups_options = [];
        foreach ($groups as $group) {
            $visible_groups_options[] = [
                'id_option' => $group['id_group'],
                'name' => $group['name']
            ];
        }

        $customers = Customer::getCustomers();
        $target_customer_options = [];
        foreach ($customers as $customer) {
            $target_customer_options[] = [
                'id_option' => $customer['id_customer'],
                'name' => $customer['firstname'] . ' ' . $customer['lastname'] . ' (' . $customer['email'] . ')'
            ];
        }

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Configuración del Visor de Precios de Cliente'),
                    'icon' => 'icon-user',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Cliente Objetivo'),
                        'desc' => $this->l('Selecciona el cliente cuyo precio específico se mostrará.'),
                        'name' => 'CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER',
                        'options' => array(
                            'query' => $target_customer_options,
                            'id' => 'id_option',
                            'name' => 'name'
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Grupos de Clientes que Verán este Precio'),
                        'desc' => $this->l('Selecciona los grupos que podrán ver el precio del cliente objetivo. Mantén CTRL para seleccionar varios.'),
                        'name' => 'CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS[]',
                        'multiple' => true,
                        'class' => 'chosen',
                        'options' => array(
                            'query' => $visible_groups_options,
                            'id' => 'id_option',
                            'name' => 'name'
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        $visible_groups = Configuration::get('CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS');
        $visible_groups_array = $visible_groups ? unserialize($visible_groups) : [];

        return array(
            'CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER' => Configuration::get('CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER'),
            'CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS[]' => $visible_groups_array,
        );
    }

    protected function postProcess()
    {
        if (Tools::isSubmit('submitCustomClientPriceViewerModule')) {
            $target_customer = (int)Tools::getValue('CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER');
            $visible_groups_raw = Tools::getValue('CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS');
            $visible_groups = is_array($visible_groups_raw) ? $visible_groups_raw : [];

            Configuration::updateValue('CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER', $target_customer);
            Configuration::updateValue('CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS', serialize($visible_groups));

            return $this->displayConfirmation($this->l('Configuración actualizada correctamente.'));
        }
        return '';
    }

    public function hookHeader()
    {
        $this->context->controller->addCSS($this->_path . 'views/css/front.css');
    }

    public function hookDisplayProductPriceBlock($params)
    {
        if ($params['type'] !== 'custom_price') {
            return;
        }

        $id_product = (int)Tools::getValue('id_product');
        if (!$id_product && isset($params['product']) && is_object($params['product'])) {
            $id_product = (int)$params['product']->id;
        } elseif (!$id_product && isset($params['product']['id_product'])) {
            $id_product = (int)$params['product']['id_product'];
        }

        if (!$id_product) {
            return;
        }

        $product = new Product($id_product, false, $this->context->language->id);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        $target_customer_id = (int)Configuration::get('CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER');
        $visible_groups_serialized = Configuration::get('CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS');
        $visible_groups = $visible_groups_serialized ? unserialize($visible_groups_serialized) : [];

        if (!$target_customer_id || empty($visible_groups)) {
            return;
        }

        $current_customer_groups = Customer::getGroupsStatic((int)$this->context->customer->id);
        $can_see_price = false;
        if ($this->context->customer->isLogged()) {
            foreach ($current_customer_groups as $customer_group_id) {
                if (in_array($customer_group_id, $visible_groups)) {
                    $can_see_price = true;
                    break;
                }
            }
        } else {
            $id_default_visitor_group = (int)Configuration::get('PS_UNIDENTIFIED_GROUP');
            $id_default_guest_group = (int)Configuration::get('PS_GUEST_GROUP');
            if (in_array($id_default_visitor_group, $visible_groups) || in_array($id_default_guest_group, $visible_groups)) {
                $can_see_price = true;
            }
        }

        if (!$can_see_price) {
            return;
        }

        $id_product_attribute = null;
        if (isset($params['product_attribute_id'])) {
            $id_product_attribute = (int)$params['product_attribute_id'];
        } elseif (Tools::getIsset('id_product_attribute')) {
            $id_product_attribute = (int)Tools::getValue('id_product_attribute');
        }
        if (!$id_product_attribute && isset($params['product']) && isset($params['product']->id_product_attribute)) {
            $id_product_attribute = (int)$params['product']->id_product_attribute;
        }
        $id_product_attribute = $id_product_attribute ?: null;

        $use_tax = Product::getTaxCalculationMethod((int)$this->context->customer->id) != PS_TAX_EXC;
        $specific_price_output = null;
        $customer_price = Product::getPriceStatic(
            $id_product,
            $use_tax = true,
            $id_product_attribute,
            6,
            null,
            false,
            true,
            1,
            false,
            $target_customer_id, // Use the target customer ID here
            null,
            null,
            $specific_price_output,
            true,
            true,
            $this->context,
            true,
            null, // $id_group is null as we are using customer price
            null,
            true // $use_customer_price is true to fetch specific price for the customer
        );

        if ($customer_price === null || $customer_price === false) {
            return;
        }

        $target_customer = new Customer($target_customer_id);
        $target_customer_name = Validate::isLoadedObject($target_customer) ? $target_customer->firstname . ' ' . $target_customer->lastname : $this->l('Cliente Especial');

        $this->context->smarty->assign(array(
            'custom_group_price' => Tools::displayPrice($customer_price),
            'custom_group_name' => $target_customer_name . ' ' . $this->l('Price'),
        ));

        return $this->display(__FILE__, 'views/templates/hook/displayProductGroupPrice.tpl');
    }
}