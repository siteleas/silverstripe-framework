<?php

namespace SilverStripe\Forms;

use SilverStripe\Core\Convert;
use SilverStripe\View\Requirements;
use Zend_Locale;
use Zend_Date;
use InvalidArgumentException;

/**
 * A composite field for date and time entry,
 * based on {@link DateField} and {@link TimeField}.
 * Usually saves into a single {@link DBDateTime} database column.
 * If you want to save into {@link Date} or {@link Time} columns,
 * please instanciate the fields separately.
 *
 * # Configuration
 *
 * The {@link setConfig()} method is only used to configure common properties of this field.
 * To configure the {@link DateField} and {@link TimeField} instances contained within, use their own
 * {@link setConfig()} methods.
 *
 * Example:
 * <code>
 * $field = new DatetimeField('Name', 'Label');
 * $field->setConfig('datavalueformat', 'yyyy-MM-dd HH:mm'); // global setting
 * $field->getDateField()->setConfig('showcalendar', 1); // field-specific setting
 * </code>
 *
 * - "timezone": Set a different timezone for viewing. {@link dataValue()} will still save
 * the time in PHP's default timezone (date_default_timezone_get()), its only a view setting.
 * Note that the sub-fields ({@link getDateField()} and {@link getTimeField()})
 * are not timezone aware, and will have their values set in local time, rather than server time.
 * - "datetimeorder": An sprintf() template to determine in which order the date and time values will
 * be combined. This is necessary as those separate formats are set in their invididual fields.
 */
class DatetimeField extends FormField
{

    /**
     * @var DateField
     */
    protected $dateField = null;

    /**
     * @var TimeField
     */
    protected $timeField = null;

    /**
     * @var HiddenField
     */
    protected $timezoneField = null;

    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_DATETIME;

    /**
     * @config
     * @var array
     */
    private static $default_config = array(
        'datavalueformat' => 'yyyy-MM-dd HH:mm:ss',
        'usertimezone' => null,
        'datetimeorder' => '%s %s',
    );

    /**
     * @var array
     */
    protected $config;

    public function __construct($name, $title = null, $value = "")
    {
        $this->config = $this->config()->default_config;

        $this->timeField = TimeField::create($name . '[time]', false);
        $this->dateField = DateField::create($name . '[date]', false);
        $this->timezoneField = new HiddenField($name . '[timezone]');

        parent::__construct($name, $title, $value);
    }

    public function setForm($form)
    {
        parent::setForm($form);

        $this->dateField->setForm($form);
        $this->timeField->setForm($form);
        $this->timezoneField->setForm($form);

        return $this;
    }

    public function setName($name)
    {
        parent::setName($name);

        $this->dateField->setName($name . '[date]');
        $this->timeField->setName($name . '[time]');
        $this->timezoneField->setName($name . '[timezone]');

        return $this;
    }

    /**
     * @param array $properties
     * @return string
     */
    public function FieldHolder($properties = array())
    {
        $config = array(
            'datetimeorder' => $this->getConfig('datetimeorder'),
        );
        $config = array_filter($config);
        $this->addExtraClass(Convert::raw2json($config));

        return parent::FieldHolder($properties);
    }

    /**
     * @param array $properties
     * @return string
     */
    public function Field($properties = array())
    {
        return parent::Field($properties);
    }

    /**
     * Sets the internal value to ISO date format, based on either a database value in ISO date format,
     * or a form submssion in the user date format. Uses the individual date and time fields
     * to take care of the actual formatting and value conversion.
     *
     * Value setting happens *before* validation, so we have to set the value even if its not valid.
     *
     * Caution: Only converts user timezones when value is passed as array data (= form submission).
     * Weak indication, but unfortunately the framework doesn't support a distinction between
     * setting a value from the database, application logic, and user input.
     *
     * @param string|array $val String expects an ISO date format. Array notation with 'date' and 'time'
     *  keys can contain localized strings. If the 'dmyfields' option is used for {@link DateField},
     *  the 'date' value may contain array notation was well (see {@link DateField->setValue()}).
     * @return $this
     */
    public function setValue($val)
    {
        $locale = new Zend_Locale($this->locale);

        // If timezones are enabled, assume user data needs to be reverted to server timezone
        if ($this->getConfig('usertimezone')) {
            // Accept user input on timezone, but only when timezone support is enabled
            $userTz = (is_array($val) && array_key_exists('timezone', $val)) ? $val['timezone'] : null;
            if (!$userTz) {
                $userTz = $this->getConfig('usertimezone'); // fall back to defined timezone
            }
        } else {
            $userTz = null;
        }

        if (empty($val)) {
            $this->value = null;
            $this->dateField->setValue(null);
            $this->timeField->setValue(null);
        } else {
            // Case 1: String setting from database, in ISO date format
            if (is_string($val) && Zend_Date::isDate($val, $this->getConfig('datavalueformat'), $locale)) {
                $this->value = $val;
            } // Case 2: Array form submission with user date format
            elseif (is_array($val) && array_key_exists('date', $val) && array_key_exists('time', $val)) {
                $dataTz = date_default_timezone_get();
                // If timezones are enabled, assume user data needs to be converted to server timezone
                if ($userTz) {
                    date_default_timezone_set($userTz);
                }

                // Uses sub-fields to temporarily write values and delegate dealing with their normalization,
                // actual sub-field value setting happens later
                $this->dateField->setValue($val['date']);
                $this->timeField->setValue($val['time']);
                if ($this->dateField->dataValue() && $this->timeField->dataValue()) {
                    $userValueObj = new Zend_Date(null, null, $locale);
                    $userValueObj->setDate(
                        $this->dateField->dataValue(),
                        $this->dateField->getConfig('datavalueformat')
                    );
                    $userValueObj->setTime(
                        $this->timeField->dataValue(),
                        $this->timeField->getConfig('datavalueformat')
                    );
                    if ($userTz) {
                        $userValueObj->setTimezone($dataTz);
                    }
                    $this->value = $userValueObj->get($this->getConfig('datavalueformat'), $locale);
                    unset($userValueObj);
                } else {
                    // Validation happens later, so set the raw string in case Zend_Date doesn't accept it
                    $this->value = trim(sprintf($this->getConfig('datetimeorder'), $val['date'], $val['time']));
                }

                if ($userTz) {
                    date_default_timezone_set($dataTz);
                }
            } // Case 3: Value is invalid, but set it anyway to allow validation by the fields later on
            else {
                $this->dateField->setValue($val);
                if (is_string($val)) {
                    $this->timeField->setValue($val);
                }
                $this->value = $val;
            }

            // view settings (dates might differ from $this->value based on user timezone settings)
            if (Zend_Date::isDate($this->value, $this->getConfig('datavalueformat'), $locale)) {
                $valueObj = new Zend_Date($this->value, $this->getConfig('datavalueformat'), $locale);
                if ($userTz) {
                    $valueObj->setTimezone($userTz);
                }

                // Set view values in sub-fields
                if ($this->dateField->getConfig('dmyfields')) {
                    $this->dateField->setValue($valueObj->toArray());
                } else {
                    $this->dateField->setValue(
                        $valueObj->get($this->dateField->getConfig('dateformat'), $locale)
                    );
                }
                $this->timeField->setValue($valueObj->get($this->timeField->getConfig('timeformat'), $locale));
            }
        }

        return $this;
    }

    public function Value()
    {
        $valDate = $this->dateField->Value();
        $valTime = $this->timeField->Value();
        if (!$valTime) {
            $valTime = '00:00:00';
        }

        return sprintf($this->getConfig('datetimeorder'), $valDate, $valTime);
    }

    public function setDisabled($bool)
    {
        parent::setDisabled($bool);
        $this->dateField->setDisabled($bool);
        $this->timeField->setDisabled($bool);
        if ($this->timezoneField) {
            $this->timezoneField->setDisabled($bool);
        }
        return $this;
    }

    public function setReadonly($bool)
    {
        parent::setReadonly($bool);
        $this->dateField->setReadonly($bool);
        $this->timeField->setReadonly($bool);
        if ($this->timezoneField) {
            $this->timezoneField->setReadonly($bool);
        }
        return $this;
    }

    /**
     * @return DateField
     */
    public function getDateField()
    {
        return $this->dateField;
    }

    /**
     * @param FormField $field
     */
    public function setDateField($field)
    {
        $expected = $this->getName() . '[date]';
        if ($field->getName() != $expected) {
            throw new InvalidArgumentException(sprintf(
                'Wrong name format for date field: "%s" (expected "%s")',
                $field->getName(),
                $expected
            ));
        }

        $field->setForm($this->getForm());
        $this->dateField = $field;
        $this->setValue($this->value); // update value
    }

    /**
     * @return TimeField
     */
    public function getTimeField()
    {
        return $this->timeField;
    }

    /**
     * @param FormField $field
     */
    public function setTimeField($field)
    {
        $expected = $this->getName() . '[time]';
        if ($field->getName() != $expected) {
            throw new InvalidArgumentException(sprintf(
                'Wrong name format for time field: "%s" (expected "%s")',
                $field->getName(),
                $expected
            ));
        }

        $field->setForm($this->getForm());
        $this->timeField = $field;
        $this->setValue($this->value); // update value
    }

    /**
     * Check if timezone field is included
     *
     * @return bool
     */
    public function getHasTimezone()
    {
        return $this->getConfig('usertimezone');
    }

    /**
     * @return FormField
     */
    public function getTimezoneField()
    {
        return $this->timezoneField;
    }

    public function setLocale($locale)
    {
        $this->dateField->setLocale($locale);
        $this->timeField->setLocale($locale);
        return $this;
    }

    public function getLocale()
    {
        return $this->dateField->getLocale();
    }

    /**
     * Note: Use {@link getDateField()} and {@link getTimeField()}
     * to set field-specific config options.
     *
     * @param string $name
     * @param mixed $val
     * @return $this
     */
    public function setConfig($name, $val)
    {
        $this->config[$name] = $val;

        if ($name == 'usertimezone') {
            $this->timezoneField->setValue($val);
            $this->setValue($this->dataValue());
        }

        return $this;
    }

    /**
     * Note: Use {@link getDateField()} and {@link getTimeField()}
     * to get field-specific config options.
     *
     * @param String $name Optional, returns the whole configuration array if empty
     * @return mixed
     */
    public function getConfig($name = null)
    {
        if ($name) {
            return isset($this->config[$name]) ? $this->config[$name] : null;
        } else {
            return $this->config;
        }
    }

    public function validate($validator)
    {
        $dateValid = $this->dateField->validate($validator);
        $timeValid = $this->timeField->validate($validator);

        return ($dateValid && $timeValid);
    }

    public function performReadonlyTransformation()
    {
        $field = clone $this;
        $field->setReadonly(true);
        return $field;
    }

    public function __clone()
    {
        $this->dateField = clone $this->dateField;
        $this->timeField = clone $this->timeField;
        $this->timezoneField = clone $this->timezoneField;
    }
}
