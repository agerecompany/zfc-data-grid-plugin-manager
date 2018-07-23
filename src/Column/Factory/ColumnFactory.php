<?php
/**
 * Flexible Column Factory
 *
 * @category Popov
 * @package Popov_ZfcDataGrid
 * @author Serhii Popov <popow.serhii@gmail.com>
 * @datetime: 19.12.15 17:44
 */
namespace Popov\ZfcDataGridPlugin\Column\Factory;

use Closure;
use Zend\Stdlib\Exception;
use Zend\Filter\Word\SeparatorToCamelCase;
use ZfcDatagrid\Column\AbstractColumn;
use ZfcDatagrid\Column\Select;
use ZfcDatagrid\Column\Formatter;
use Popov\Simpler\SimplerHelper;
use Popov\ZfcDataGridPlugin\Service\Plugin\DataGridPluginManager;

class ColumnFactory
{
    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var SimplerHelper
     */
    protected $simpler;

    /**
     * @var DataGridPluginManager
     */
    protected $columnPluginManager;

    /**
     * @var AbstractColumn[]
     */
    protected $columns;

    /**
     * @var Closure[]
     */
    protected $deferredPreparation = [];

    public function __construct(DataGridPluginManager $columnPluginManager, SimplerHelper $simpler, $config)
    {
        $this->config = $config;
        $this->simpler = $simpler;
        $this->columnPluginManager = $columnPluginManager;
    }

    public function getDataGridPluginManager()
    {
        return $this->columnPluginManager;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getSimpler()
    {
        return $this->simpler;
    }

    public function create($config)
    {
        $column = $this->doCreate($config, 'Column');
        $this->columns[$column->getUniqueId()] = $column;

        $this->runDeferredPreparation($column);

        return $column;
    }

    /**
     * @param string $id
     *
     * @return AbstractColumn|bool
     */
    public function getColumn($id)
    {
        if (isset($this->columns[$id])) {
            return $this->columns[$id];
        }

        return false;
    }

    /**
     * Get standardizes config key
     *
     * @param $key
     * @return string
     */
    public function getConfigKey($key)
    {
        return strtolower(preg_replace("/[^A-Za-z0-9]/", '', $key));
    }

    /**
     * We can have specific requirement such as placeholder_column generation when real column wasn't registered yet.
     * In those cases we can register closure with specific condition and execute it when condition is true.
     *
     * For performance boost, you can return true from a closure if preparation must be executed only once.
     * In such case this preparation consider as executed and will remove from stack.
     *
     * @param AbstractColumn $column
     */
    protected function runDeferredPreparation($column)
    {
        foreach ($this->deferredPreparation as $key => $preparation) {
            if ($preparation($column)) {
                unset($this->deferredPreparation[$key]);
            }
        }
    }

    public function addDeferredPreparation($closure)
    {
        $this->deferredPreparation[] = $closure;
    }

    protected function doCreate($config, $group)
    {
        // suppose $config value can be simple string as ['type' => 'DateTime']
        if (is_string($config)) {
            $config = ['name' => $config];
        }

        if (/*is_array($config) && */(!isset($config['name']) || !$config['name'])) {
            throw new Exception\RuntimeException($group . ' "name" key must be set and cannot be empty');
        }

        $gpm = $this->getDataGridPluginManager();

        //$className = is_array($config['name']) ? $config['name']['name'] : $config['name'];
        //$className = (isset($config['name']) ? $config['name'] : $config) . $group;
        $className = $config['name'] . $group;
        if (!$gpm->has($className)) {
            throw new Exception\RuntimeException(sprintf(
                '%s "%s" not fount in the "data_grid_plugins" key of configuration array', $group, $config['name']
            ));
        }

        /** @var \ZfcDatagrid\Column\Action $object */
        if (isset($config['construct'])) {
            $className = $gpm->getInvokableClass($className);
            //$object = new $className(...$config['construct']);

            // @link http://stackoverflow.com/a/2409288/1335142
            //if (version_compare(phpversion(), '7.0.0', '>=')) {
            //	$object = new $className(eval('...') . $config['construct']);
            //} else {
            $reflect = new \ReflectionClass($className);
            $object = $reflect->newInstanceArgs($config['construct']);
            //}
        } else {
            $object = $gpm->get($className);
        }

        $this->configSetter($object, $config);

        return $object;
    }

    public function createActions($config)
    {
        $actions = [];
        foreach ($config as $actionConfig) {
            $actions[] = $this->doCreate($actionConfig, 'Action');
        }

        return $actions;
    }

    public function createFormatters($config)
    {
        $formatters = [];
        foreach ($config as $formatterConfig) {
            $formatters[] = $this->doCreate($formatterConfig, 'Formatter');
        }

        return $formatters;
    }

    public function createStyles($config)
    {
        $styles = [];
        foreach ($config as $styleConfig) {
            $styles[] = $this->doCreate($styleConfig, 'Style');
        }

        return $styles;
    }

    public function createType($config)
    {
        $type = $this->doCreate($config, 'Type');

        return $type;
    }

    public function configSetter($object, $config)
    {
        //$cpm = $this->getDataGridPluginManager();

        $merged = $config;
        $generalConfig = $this->getConfig();
        $className = get_class($object);

        // merge config based on class name
        if (isset($generalConfig['data_grid_plugins_config'][$className])) {
            $pluginConfig = $generalConfig['data_grid_plugins_config'][$className];
            $merged = array_merge($pluginConfig, $merged);
        }

        // merge config based on column Type
        if (($classTypeName = $this->getClassName($config, 'type'))
            && isset($generalConfig['data_grid_plugins_config']['type_of'][$classTypeName])
        ) {
            $typeConfig = $generalConfig['data_grid_plugins_config']['type_of'][$classTypeName];
            $merged = array_merge($typeConfig, $merged);
        }

        // set object parameters based on config
        $filter = new SeparatorToCamelCase('_');
        foreach ($merged as $key => $value) {
            if (in_array($key, ['name', 'construct'])) {
                continue;
            }

            $suffix = ucfirst($filter->filter($key));
            // create sub objects: action, formatter etc.
            if (method_exists($this, $method = 'create' . $suffix)) {
                $options = $this->{$method}($value);
                $object->{'set' . $suffix}($options);

                continue;
            }

            $method = 'set' . $suffix;
            if (is_array($value)) {
                if (!method_exists($object, $method)) {
                    // setter for array options like attributes which need be set peer iteration
                    $suffix = substr($suffix, 0, strlen($suffix) - 1); // to singular (remove "s")
                    if (!method_exists($object, $setMethod = $method = 'set' . $suffix)) {
                        if (!method_exists($object, $addMethod = $method = 'add' . $suffix)) {
                            throw new Exception\BadMethodCallException(sprintf(
                                'Neither %s method nor %s method found in class %s',
                                $setMethod,
                                $addMethod,
                                get_class($object)
                            ));
                        }
                    }

                    // prepare special attribute like link or etc.
                    foreach ($value as $attribute => $val) {
                        if (is_array($val)) {
                            call_user_func_array([$object, $method], $val);
                        } else {
                            $object->{$method}($attribute, $val);
                        }
                    }
                } else {
                    // prepare special attribute like link or etc.
                    if (method_exists($this, $prepareMethod = 'prepareAttribute' . $suffix)) {
                        $value = $this->{$prepareMethod}($object, $value);
                    }

                    if (is_array($value)) {
                        call_user_func_array([$object, $method], $value);
                    } else {
                        $object->{$method}($value);
                    }
                }
            } else {
                $object->{$method}($value);
            }
        }
    }

    /**
     * Get real class name one of DataGrid item
     * @param $alias
     * @param string $group
     * @return bool
     */
    public function getClassName($alias, $group = null)
    {
        $cpm = $this->getDataGridPluginManager();

        if (is_array($alias)) {
            if ($group) {
                if (isset($alias[$group]) && is_string($alias[$group])) {
                    $alias = $alias[$group];
                } elseif (isset($alias[$group]['name'])) {
                    $alias = $alias[$group]['name'];
                } else {
                    return false;
                }
            } elseif (isset($alias['name'])) {
                $alias = $alias['name'];
            } else {
                return false;
            }
        }

        $alias .= ucfirst($group ?: 'column');

        return $cpm->getInvokableClass($alias);
    }

    /**
     * Prepare "link" attribute value based on special array configuration.
     *
     * Config key:
     *      href - not changed link path
     *      placeholder_column - Column object or column id for get placeholder value
     *
     * @param Formatter\Link $formatter
     * @param $params
     * @return string
     */
    public function prepareAttributeLink(Formatter\Link $formatter, $params)
    {
        if (!is_array($params)) {
            return $params;
        }

        $marks = [];
        $placeholders = (array) $params['placeholder_column'];
        foreach ($placeholders as $placeholder) {
            if (is_string($placeholder) && ($column = $this->getColumn($placeholder))) {
                //$href = $params['href'] . '/' . $formatter->getColumnValuePlaceholder($column);
                $marks[] = $formatter->getColumnValuePlaceholder($column);
            } elseif (($placeholder instanceof AbstractColumn)) {
                $marks[] = $formatter->getColumnValuePlaceholder($placeholder);
            } else {
                $marks[] = ':' . $placeholder . ':';

                // When "placeholder_column" is string, such as "item_code",
                // we don't know if column was already added or will be added during next calls.
                // That's why we postpone column register in getColumnValuePlaceholder
                // and add this task as a closure for next check.
                $this->addDeferredPreparation(function(AbstractColumn $column) use ($formatter, $placeholders) {
                    foreach ($placeholders as $placeholder) {
                        ($column->getUniqueId() === $placeholder)
                            ? $formatter->getColumnValuePlaceholder($column)
                            : false;
                    }
                });
            }
        }

        $href = sprintf($params['href'], ...$marks);

        return $href;
    }

    public function prepareAttributeFilterSelectOptions(Select $object, $params)
    {
        if (!isset($params['options'])) {
            return $params;
        }

        $options = $params['options'];
        $om = $options['object_manager'];

        $repository = $om->getRepository($options['target_class']);
        if ($options['is_method']) {
            $method = $options['find_method']['name'];
            $values = call_user_func_array([$repository, $method], $options['find_method']['params']);
        } else {
            $values = $repository->findAll();
        }
        $identifier = isset($options['identifier']) ? $options['identifier'] : 'identifier';
        $options = $this->getSimpler()->setContext($values)->asArrayValue($options['property'], $identifier);

        return [$options];
    }
}
