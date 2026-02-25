<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CustomClientPriceViewer extends Module
{
    private const CONFIG_TARGET_CUSTOMER = 'CUSTOMCLIENTPRICEVIEWER_TARGET_CUSTOMER';
    private const CONFIG_VISIBLE_GROUPS = 'CUSTOMCLIENTPRICEVIEWER_VISIBLE_GROUPS';

    public function __construct()
    {
        $this->name = 'customclientpriceviewer';
        $this->tab = 'front_office_features';
        $this->version = '1.0.2';
        $this->author = 'Tu Nombre';
        $this->need_instance = 1;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Visor de Precio de Cliente Personalizado', array(), 'Modules.Customclientpriceviewer.Admin');
        $this->description = $this->trans('Muestra el precio de un cliente específico en la página de producto, visible solo para grupos seleccionados.', array(), 'Modules.Customclientpriceviewer.Admin');
        $this->ps_versions_compliancy = array('min' => '8.1.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (
            !$this->registerHook('header')
            || !$this->registerHook('displayProductPriceBlock')
        ) {
            parent::uninstall();

            return false;
        }

        return Configuration::updateValue(self::CONFIG_TARGET_CUSTOMER, 0)
            && Configuration::updateValue(self::CONFIG_VISIBLE_GROUPS, json_encode(array()));
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CONFIG_TARGET_CUSTOMER);
        Configuration::deleteByName(self::CONFIG_VISIBLE_GROUPS);

        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCustomClientPriceViewerModule')) {
            $output .= $this->postProcess();
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCustomClientPriceViewerModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        $groups = Group::getGroups((int) $this->context->language->id);
        $visibleGroupsOptions = array();
        foreach ($groups as $group) {
            $visibleGroupsOptions[] = array(
                'id_option' => (int) $group['id_group'],
                'name' => (string) $group['name'],
            );
        }

        $customers = Customer::getCustomers();
        $targetCustomerOptions = array(
            array(
                'id_option' => 0,
                'name' => $this->trans('-- Selecciona un cliente --', array(), 'Modules.Customclientpriceviewer.Admin'),
            ),
        );

        foreach ($customers as $customer) {
            $targetCustomerOptions[] = array(
                'id_option' => (int) $customer['id_customer'],
                'name' => trim($customer['firstname'] . ' ' . $customer['lastname']) . ' (' . $customer['email'] . ')',
            );
        }

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Configuración del Visor de Precios de Cliente', array(), 'Modules.Customclientpriceviewer.Admin'),
                    'icon' => 'icon-user',
                ),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Cliente objetivo', array(), 'Modules.Customclientpriceviewer.Admin'),
                        'desc' => $this->trans('Selecciona el cliente cuyo precio específico se mostrará.', array(), 'Modules.Customclientpriceviewer.Admin'),
                        'name' => self::CONFIG_TARGET_CUSTOMER,
                        'required' => true,
                        'options' => array(
                            'query' => $targetCustomerOptions,
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->trans('Grupos con visibilidad', array(), 'Modules.Customclientpriceviewer.Admin'),
                        'desc' => $this->trans('Selecciona los grupos que podrán ver este precio.', array(), 'Modules.Customclientpriceviewer.Admin'),
                        'name' => self::CONFIG_VISIBLE_GROUPS . '[]',
                        'multiple' => true,
                        'class' => 'chosen',
                        'options' => array(
                            'query' => $visibleGroupsOptions,
                            'id' => 'id_option',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Guardar', array(), 'Admin.Actions'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            self::CONFIG_TARGET_CUSTOMER => (int) Configuration::get(self::CONFIG_TARGET_CUSTOMER),
            self::CONFIG_VISIBLE_GROUPS . '[]' => $this->getVisibleGroupsConfig(),
        );
    }

    protected function postProcess()
    {
        $targetCustomerId = (int) Tools::getValue(self::CONFIG_TARGET_CUSTOMER);
        $visibleGroupsRaw = Tools::getValue(self::CONFIG_VISIBLE_GROUPS);
        $visibleGroups = $this->sanitizeVisibleGroups($visibleGroupsRaw);

        if (!Validate::isUnsignedId($targetCustomerId) || $targetCustomerId <= 0) {
            return $this->displayError($this->trans('Debes seleccionar un cliente válido.', array(), 'Modules.Customclientpriceviewer.Admin'));
        }

        $targetCustomer = new Customer($targetCustomerId);
        if (!Validate::isLoadedObject($targetCustomer)) {
            return $this->displayError($this->trans('El cliente seleccionado no existe.', array(), 'Modules.Customclientpriceviewer.Admin'));
        }

        if (!Configuration::updateValue(self::CONFIG_TARGET_CUSTOMER, $targetCustomerId)) {
            return $this->displayError($this->trans('No se pudo guardar el cliente objetivo.', array(), 'Modules.Customclientpriceviewer.Admin'));
        }

        if (!Configuration::updateValue(self::CONFIG_VISIBLE_GROUPS, json_encode($visibleGroups))) {
            return $this->displayError($this->trans('No se pudieron guardar los grupos visibles.', array(), 'Modules.Customclientpriceviewer.Admin'));
        }

        return $this->displayConfirmation($this->trans('Configuración actualizada correctamente.', array(), 'Modules.Customclientpriceviewer.Admin'));
    }

    public function hookHeader()
    {
        if (!$this->context->controller) {
            return;
        }

        $this->context->controller->registerStylesheet(
            $this->name . '-front',
            'modules/' . $this->name . '/views/css/front.css',
            array('media' => 'all', 'priority' => 150)
        );
    }

    public function hookDisplayProductPriceBlock($params)
    {
        if (!isset($params['type']) || $params['type'] !== 'custom_price') {
            return;
        }

        $targetCustomerId = (int) Configuration::get(self::CONFIG_TARGET_CUSTOMER);
        $visibleGroups = $this->getVisibleGroupsConfig();
        if ($targetCustomerId <= 0 || empty($visibleGroups)) {
            return;
        }

        $targetCustomer = new Customer($targetCustomerId);
        if (!Validate::isLoadedObject($targetCustomer)) {
            return;
        }

        $idProduct = $this->resolveProductId($params);
        if ($idProduct <= 0) {
            return;
        }

        $product = new Product($idProduct, false, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        if (!$this->canCurrentVisitorSeeCustomPrice($visibleGroups)) {
            return;
        }

        $idProductAttribute = $this->resolveProductAttributeId($params);
        $useTax = Product::getTaxCalculationMethod((int) $this->context->customer->id) != PS_TAX_EXC;
        $specificPriceOutput = null;

        $customerPrice = Product::getPriceStatic(
            $idProduct,
            $useTax,
            $idProductAttribute,
            6,
            null,
            false,
            true,
            1,
            false,
            $targetCustomerId,
            null,
            null,
            $specificPriceOutput,
            true,
            true,
            $this->context,
            true,
            null,
            null,
            true
        );

        if ($customerPrice === null || $customerPrice === false) {
            return;
        }

        $this->context->smarty->assign(array(
            'custom_group_price' => Tools::displayPrice($customerPrice),
            'custom_group_label' => $this->trans('PVP', array(), 'Modules.Customclientpriceviewer.Shop'),
        ));

        return $this->display(__FILE__, 'views/templates/hook/displayProductGroupPrice.tpl');
    }

    private function sanitizeVisibleGroups($visibleGroupsRaw)
    {
        if (!is_array($visibleGroupsRaw)) {
            return array();
        }

        $availableGroupIds = array();
        foreach (Group::getGroups((int) $this->context->language->id) as $group) {
            $availableGroupIds[] = (int) $group['id_group'];
        }

        $sanitized = array();
        foreach ($visibleGroupsRaw as $groupId) {
            $groupId = (int) $groupId;
            if (Validate::isUnsignedId($groupId) && in_array($groupId, $availableGroupIds, true)) {
                $sanitized[] = $groupId;
            }
        }

        return array_values(array_unique($sanitized));
    }

    private function resolveProductId($params)
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct > 0) {
            return $idProduct;
        }

        if (isset($params['product']) && is_object($params['product']) && isset($params['product']->id)) {
            return (int) $params['product']->id;
        }

        if (isset($params['product']) && is_array($params['product']) && isset($params['product']['id_product'])) {
            return (int) $params['product']['id_product'];
        }

        return 0;
    }

    private function resolveProductAttributeId($params)
    {
        if (isset($params['product_attribute_id'])) {
            return (int) $params['product_attribute_id'];
        }

        if (Tools::getIsset('id_product_attribute')) {
            return (int) Tools::getValue('id_product_attribute');
        }

        if (isset($params['product']) && is_object($params['product']) && isset($params['product']->id_product_attribute)) {
            return (int) $params['product']->id_product_attribute;
        }

        return null;
    }

    private function canCurrentVisitorSeeCustomPrice(array $visibleGroups)
    {
        if ($this->context->customer->isLogged()) {
            $currentCustomerGroups = Customer::getGroupsStatic((int) $this->context->customer->id);
            foreach ($currentCustomerGroups as $customerGroupId) {
                if (in_array((int) $customerGroupId, $visibleGroups, true)) {
                    return true;
                }
            }

            return false;
        }

        $visitorGroupId = (int) Configuration::get('PS_UNIDENTIFIED_GROUP');
        $guestGroupId = (int) Configuration::get('PS_GUEST_GROUP');

        return in_array($visitorGroupId, $visibleGroups, true) || in_array($guestGroupId, $visibleGroups, true);
    }

    private function getVisibleGroupsConfig()
    {
        $visibleGroupsRaw = (string) Configuration::get(self::CONFIG_VISIBLE_GROUPS);
        if ($visibleGroupsRaw === '') {
            return array();
        }

        $visibleGroups = json_decode($visibleGroupsRaw, true);
        if (!is_array($visibleGroups)) {
            return array();
        }

        $sanitized = array();
        foreach ($visibleGroups as $groupId) {
            $groupId = (int) $groupId;
            if (Validate::isUnsignedId($groupId)) {
                $sanitized[] = $groupId;
            }
        }

        return array_values(array_unique($sanitized));
    }
}
