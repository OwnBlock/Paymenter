<?php

namespace App\Helpers;

use App\Models\ConfigurableOption;
use App\Models\ConfigurableOptionInput;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Extension;
use App\Models\OrderProduct;
use App\Models\OrderProductConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\View;

class ExtensionHelper
{
    /**
     * Called when a new invoice is accepted.
     *
     * @param int $id ID of the invoice
     *
     * @return void
     */
    public static function paymentDone($id, $paymentMethod = 'manual', $paymentReference = null)
    {
        $invoice = Invoice::findOrFail($id);

        // Is the invoice for credits? Then add them to the user's account.
        if ($invoice->credits > 0) {
            $user = User::findOrFail($invoice->user_id);
            $user->credits = $user->credits + $invoice->credits;
            $user->save();

            $invoice->status = 'paid';
            $invoice->save();
            return;
        }

        $invoice->status = 'paid';
        $invoice->paid_with = $paymentMethod;
        $invoice->paid_reference = $paymentReference;
        $invoice->paid_at = now();
        $invoice->save();

        foreach ($invoice->items()->get() as $item) {
            $product = $item->product()->get()->first();
            if (!$product) {
                continue;
            }

            if ($product->status == 'suspended') {
                ExtensionHelper::unsuspendServer($product);
            }
            if ($product->status == 'pending') {
                ExtensionHelper::createServer($product);
            }
            if ($product->status == 'pending' || $product->status == 'suspended') {
                if ($product->billing_cycle) {
                    if ($product->billing_cycle == 'monthly') {
                        $product->expiry_date = Carbon::now()->addMonth();
                    } elseif ($product->billing_cycle == 'quarterly') {
                        $product->expiry_date = Carbon::now()->addMonths(3);
                    } elseif ($product->billing_cycle == 'semi_annually') {
                        $product->expiry_date = Carbon::now()->addMonths(6);
                    } elseif ($product->billing_cycle == 'annually') {
                        $product->expiry_date = Carbon::now()->addYear();
                    } elseif ($product->billing_cycle == 'biennially') {
                        $product->expiry_date = Carbon::now()->addYears(2);
                    } elseif ($product->billing_cycle == 'triennially') {
                        $product->expiry_date = Carbon::now()->addYears(3);
                    }
                }
            } else {
                if ($product->billing_cycle) {
                    if ($product->billing_cycle == 'monthly') {
                        $product->expiry_date = Carbon::parse($product->expiry_date)->addMonth();
                    } elseif ($product->billing_cycle == 'quarterly') {
                        $product->expiry_date = Carbon::parse($product->expiry_date)->addMonths(3);
                    } elseif ($product->billing_cycle == 'semi_annually') {
                        $product->expiry_date = Carbon::parse($product->expiry_date)->addMonths(6);
                    } elseif ($product->billing_cycle == 'annually') {
                        $product->expiry_date = Carbon::parse($product->expiry_date)->addYear();
                    } elseif ($product->billing_cycle == 'biennially') {
                        $product->expiry_date = Carbon::parse($product->expiry_date)->addYears(2);
                    } elseif ($product->billing_cycle == 'triennially') {
                        $product->expiry_date = Carbon::parse($product->expiry_date)->addYears(3);
                    }
                }
            }
            $product->status = 'paid';
            $product->save();
        }
    }


    /**
     * Called when you got a error.
     *
     * @return void
     */
    public static function error($extension, $message)
    {
        // Convert message to string
        if (is_array($message)) {
            $message = json_encode($message);
        }
        Log::error($extension . ': ' . $message);
    }

    /**
     * Called when a new order is accepted
     * ```php
     * ExtensionHelper::getConfig('paymenter', 'paymenter');
     * ```
     *
     * @param string $name Name of the extension
     * @param string $key Name of the config
     *
     * @return string
     */
    public static function getConfig($name, $key)
    {
        $extension = Extension::where('name', $name)->first();
        if (!$extension) {
            Extension::create([
                'name' => $name,
                'enabled' => false,
                'type' => 'notset',
            ]);
            $extension = Extension::where('name', $name)->first();
        }
        $config = $extension->getConfig()->where('key', $key)->first();
        if (!$config) {
            return;
        }
        try {
            return Crypt::decryptString($config->value);
        } catch (DecryptException $e) {
            return $config->value;
        }
    }

    /**
     * Sets the config of an extension
     * ```php
     * ExtensionHelper::setConfig('paymenter', 'paymenter', 'paypal');
     * ```
     *
     * @param string $name Name of the extension
     * @param string $key Name of the config
     * @param string $value Value of the config
     *
     * @return void
     */
    public static function setConfig($name, $key, $value)
    {
        $extension = Extension::where('name', $name)->first();
        if (!$extension) {
            Extension::create([
                'name' => $name,
                'enabled' => false,
                'type' => 'notset',
            ]);
            $extension = Extension::where('name', $name)->first();
        }
        $config = $extension->getConfig()->where('key', $key)->first();

        $value = Crypt::encryptString($value);

        if (!$config) {
            $extension->getConfig()->create([
                'key' => $key,
                'value' => $value,
            ]);
        } else {
            $config->value = $value;
            $config->save();
        }
    }

    /**
     * Get the currency
     * 
     * @return string
     */
    public static function getCurrency()
    {
        return config('settings::currency') ?? 'USD';
    }

    public static function getProductConfig($name, $key, $id)
    {
        $extension = Extension::where('name', $name)->first();
        if (!$extension) {
            Extension::create([
                'name' => $name,
                'enabled' => false,
                'type' => 'server',
            ]);
            $extension = Extension::where('name', $name)->first();
        }

        $config = $extension->getServer()->where('product_id', $id)->where('extension', $extension->id)->where('name', $key)->first();
        if (!$config) {
            $extension->getServer()->create([
                'name' => $key,
                'value' => '',
                'product_id' => $id,
                'extension' => $extension->id,
            ]);
            $config = $extension->getServer()->where('product_id', $id)->where('extension', $extension->id)->where('name', $key)->first();
        }

        return $config->value;
    }

    /**
     * Creates or updates a Product Config.
     *
     * @return void
     */
    public static function setOrderProductConfig($key, $value, $id)
    {
        $config = OrderProductConfig::where('order_product_id', $id)->where('key', $key)->first();
        if (!$config) {
            OrderProductConfig::create([
                'order_product_id' => $id,
                'key' => $key,
                'value' => $value,
            ]);
        } else {
            $config->value = $value;
            $config->save();
        }
    }

    /**
     * Get link to redirect the user to for payment
     * 
     * @param int $id ID of the extension
     * @param int $total Total price
     * @param array $products Array of products
     * @param int $orderId ID of the order
     * 
     * @return string
     */
    public static function getPaymentMethod($id, $total, $products, $orderId)
    {
        $extension = Extension::where('id', $id)->first();
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\Gateways\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $pay = $module->pay($total, $products, $orderId);

        return $pay;
    }

    /**
     * Redirect to payment url, when a user adds credits
     * 
     * @param Extension $extension
     * @param Invoice $invoice
     * 
     * @return string
     */
    public static function addCredits(Extension $extension, Invoice $invoice)
    {
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\Gateways\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $total = $invoice->credits;

        // We fake a product, so we can use the same function as for products
        $product = new Product();
        $product->name = 'Credits';
        $product->description = 'Credits';
        $product->price = $total;
        $product->quantity = 1;
        $product->id = 0;

        $pay = $module->pay($total, [$product], $invoice->id);

        return $pay;
    }


    public static function loadConfiguration(Product $product, OrderProduct $product2)
    {
        $settings = $product->settings ?? collect();
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting->name] = $setting->value;
        }
        $config['config_id'] = $product->id;
        $config['config'] = [];
        foreach ($product2->config as $config2) {
            if ($config2->is_configurable_option) {
                continue;
            }
            $config['config'][$config2->key] = $config2->value;
        }
        return $config;
    }


    /**
     * Load configurable options by product
     * 
     * @param OrderProduct $product2
     * 
     * @return array
     */
    public static function loadConfigurableOptions(OrderProduct $product2)
    {
        $configurableOptions = [];
        foreach ($product2->config as $config2) {
            if (!$config2->is_configurable_option) {
                continue;
            }
            $option = ConfigurableOption::where('id', $config2->key)->first();
            if (!$option) {
                continue;
            }
            $option->original_name = $option->name;
            $option->name = explode('|', $option->name)[0] ?? $option->name;
            $value = ConfigurableOptionInput::where('id', $config2->value)->first();
            $configurableOptions[$option->name] = $value ? $value->name : $config2->value;
        }
        return $configurableOptions;
    }

    /**
     * Get all gateways
     * 
     * @return array
     */
    public static function getGateways()
    {
        $gateways = [];
        $extensions = Extension::where('enabled', true)->where('type', 'gateway')->get();
        foreach ($extensions as $extension) {
            $gateways[] = $extension;
        }
        return $gateways;
    }

    public static function createServer(OrderProduct $product2)
    {
        $order = $product2->order()->first();
        $product = Product::findOrFail($product2->product_id);
        if (!isset($product->extension_id)) {
            return;
        }
        $extension = $product->extension;
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        $extensionName = $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $config = self::loadConfiguration($product, $product2);
        $configurableOptions = self::loadConfigurableOptions($product2);
        $user = $order->user;
        try {
            $module->createServer($user, $config, $order, $product2, $configurableOptions);
        } catch (\Exception $e) {
            self::error($extensionName, 'Error creating server: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile());
        }
    }

    public static function suspendServer(OrderProduct $product2)
    {
        $order = $product2->order()->first();
        $product = Product::findOrFail($product2->product_id);
        if (!isset($product->extension_id)) {
            return;
        }
        $extension = $product->extension;
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $config = self::loadConfiguration($product, $product2);
        $configurableOptions = self::loadConfigurableOptions($product2);
        $user = $order->user;

        try {
            $module->suspendServer($user, $config, $order, $product2, $configurableOptions);
        } catch (\Exception $e) {
            self::error($extension->name, 'Error suspending server: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile());
        }
    }

    public static function unsuspendServer(OrderProduct $product2)
    {
        $order = $product2->order()->first();

        $product = Product::findOrFail($product2->product_id);
        if (!isset($product->extension_id)) {
            return;
        }
        $extension = $product->extension;
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $config = self::loadConfiguration($product, $product2);
        $configurableOptions = self::loadConfigurableOptions($product2);
        $user = $order->user;
        try {
            $module->unsuspendServer($user, $config, $order, $product2, $configurableOptions);
        } catch (\Exception $e) {
            self::error($extension->name, 'Error creating server: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile());
        }
    }

    public static function terminateServer(OrderProduct $product2)
    {
        $order = $product2->order()->first();

        $product = Product::findOrFail($product2->product_id);
        if (!isset($product->extension_id)) {
            return;
        }
        $extension = $product->extension;
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $config = self::loadConfiguration($product, $product2);
        $configurableOptions = self::loadConfigurableOptions($product2);
        $user = $order->user;

        try {
            $module->terminateServer($user, $config, $order, $product2, $configurableOptions);
        } catch (\Exception $e) {
            self::error($extension->name, 'Error creating server: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile());
        }
    }

    /**
     * Get a (login) link for the client and admin area
     * 
     * @param OrderProduct $product
     * 
     * @return string
     */
    public static function getLink(OrderProduct $product2)
    {
        $order = $product2->order()->first();

        $product = Product::findOrFail($product2->product_id);
        if (!isset($product->extension_id)) {
            return false;
        }
        $extension = $extension = $product->extension;
        if (!$extension) {
            return false;
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return false;
        }
        $module = new $module($extension);
        $config = self::loadConfiguration($product, $product2);
        $configurableOptions = self::loadConfigurableOptions($product2);
        $user = $order->user;
        $function = $extension->name . '_getLink';
        if (!function_exists($function)) {
            return false;
        }
        $link = $module->getLink($user, $config, $order, $product2, $configurableOptions);

        return $link;
    }

    /**
     * Get the product configuration for the admin area
     * 
     * @param Product $product
     * 
     * @return array
     */
    public static function getProductConfiguration(Product $product)
    {
        if (!isset($product->extension_id)) {
            return [];
        }
        $extension = $product->extension;
        if (!$extension) {
            return [];
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return [];
        }
        $module = new $module($extension);
        $settings = $product->settings;
        $config = [];
        foreach ($settings as $setting) {
            $config[$setting->name] = $setting->value;
        }
        $config['config_id'] = $product->id;
        $config = $module->getProductConfig($config);
        return json_decode(json_encode($config));
    }

    /**
     * Get custom pages for client area
     * 
     * @param OrderProduct $product
     * 
     * @return array
     */
    public static function getCustomPages(OrderProduct $product2)
    {
        $order = $product2->order()->first();

        $product = Product::findOrFail($product2->product_id);
        if (!isset($product->extension_id)) {
            return [];
        }
        $extension = $product->extension;
        if (!$extension) {
            return [];
        }
        $module = 'App\Extensions\\Servers\\' . $extension->name . '\\' . $extension->name;
        if (!class_exists($module)) {
            return [];
        }
        $module = new $module($extension);
        if(!method_exists($module, 'getCustomPages')) {
            return [];
        }
        $config = self::loadConfiguration($product, $product2);
        $configurableOptions = self::loadConfigurableOptions($product2);
        $user = $order->user;

        View::addNamespace(strtolower($extension->name), app_path() . '/Extensions/Servers/' . $extension->name . '/views');
        try {
            return $module->getCustomPages($user, $config, $order, $product2, $configurableOptions);
        } catch (\Exception $e) {
            ExtensionHelper::error($extension->name, 'Error getting pages ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile() . ' on line ' . $e->getLine() . ' in file ' . $e->getFile());
            return [];
        }
    }


    /**
     * Get all parameters for a order product
     * 
     * @param OrderProduct $product
     * 
     * @return object
     */
    public static function getParameters(OrderProduct $product2)
    {
        $product = Product::findOrFail($product2->product_id);
        $config = self::loadConfiguration($product, $product2);
        $user = $product2->order->user;
        $configurableOptions = self::loadConfigurableOptions($product2);

        return (object) [
            'user' => $user,
            'config' => $config,
            'order' => $product2->order,
            'product' => $product2,
            'configurableOptions' => $configurableOptions,
        ];
    }

    /**
     * Check if user may access the server
     * 
     * @param OrderProduct $product
     * @param User $user
     * 
     * @return bool
     */
    public static function hasAccess(OrderProduct $product, User $user)
    {
        if ($product->order->user == $user) {
            return true;
        }

        return false;
    }
}
