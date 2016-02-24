<?php

namespace Oro\Bundle\FilterBundle\Form\EventListener;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use Oro\Bundle\FilterBundle\Expression\Date\Compiler;
use Oro\Bundle\FilterBundle\Provider\DateModifierInterface;

class DateFilterSubscriber implements EventSubscriberInterface
{
    /** @var Compiler */
    protected $expressionCompiler;

    /** @var array */
    protected $processed = [];

    /**
     * @param Compiler $compiler
     */
    public function __construct(Compiler $compiler)
    {
        $this->expressionCompiler = $compiler;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::PRE_SUBMIT => 'preSubmit'
        ];
    }

    /**
     * Parses date expressions
     * If date part given, then replace value fields by choice fields with specific to that value choices
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) many cases in switch - have no sense to refactor this.
     */
    public function preSubmit(FormEvent $event)
    {
        $compiler = $this->expressionCompiler;

        $data = $event->getData();
        $form = $event->getForm();

        $oid = spl_object_hash($form);
        if (!empty($this->processed[$oid])) {
            // ensure that data is not processed
            // in case when DateTimeFilter already process and parent subscription is not necessary
            return;
        }

        $children = array_keys($form->get('value')->all());

        // compile expressions
        $this->mapValues(
            $children,
            $data,
            function ($data) use ($compiler) {
                return $compiler->compile($data);
            }
        );
        $data['part'] = isset($data['part']) ? $data['part'] : null;

        // change value type depending on date part
        // replace value form children to needed sub forms in case when part is selected
        switch ($data['part']) {
            case DateModifierInterface::PART_MONTH:
                $this->mapValues($children, $data, $this->getDatePartAccessorClosure('m'));
                $this->replaceValueFields($form->get('value'), $this->getChoices(1, 12));
                break;
            case DateModifierInterface::PART_DOW:
                $this->mapValues($children, $data, $this->getDatePartAccessorClosure('N'));
                $this->replaceValueFields($form->get('value'), $this->getChoices(1, 7));
                break;
            case DateModifierInterface::PART_WEEK:
                $this->mapValues($children, $data, $this->getDatePartAccessorClosure('W'));
                $this->replaceValueFields($form->get('value'), $this->getChoices(1, 53));
                break;
            case DateModifierInterface::PART_DAY:
                $this->mapValues($children, $data, $this->getDatePartAccessorClosure('d'));
                $this->replaceValueFields($form->get('value'), $this->getChoices(1, 31));
                break;
            case DateModifierInterface::PART_QUARTER:
                $this->mapValues(
                    $children,
                    $data,
                    function ($data) {
                        $quarter = null;
                        switch (true) {
                            case is_numeric($data):
                                $quarter = (int)$data;
                                break;
                            case ($data instanceof \DateTime):
                                $month   = (int)$data->format('m');
                                $quarter = ceil($month / 3);
                                break;
                            default:
                                throw new UnexpectedTypeException($data, 'integer or \DateTime');
                        }

                        return $quarter;
                    }
                );
                $this->replaceValueFields($form->get('value'), $this->getChoices(1, 4));
                break;
            case DateModifierInterface::PART_DOY:
                $this->mapValues($children, $data, $this->getDatePartAccessorClosure('z'));
                $this->replaceValueFields($form->get('value'), $this->getChoices(1, 366));
                break;
            case DateModifierInterface::PART_YEAR:
                $this->mapValues($children, $data, $this->getDatePartAccessorClosure('Y'));
                $this->replaceValueFields($form->get('value'), array_flip(range(date('Y') - 100, date('Y') + 50)));
                break;
            case DateModifierInterface::PART_VALUE:
            default:
                $this->mapValues(
                    $children,
                    $data,
                    function ($data) use ($compiler) {
                        // html5 format for intl
                        return $data instanceof \DateTime ? $data->format('Y-m-d H:i') : $data;
                    }
                );
                break;
        }

        $event->setData($data);
        $this->processed[$oid] = true;
    }

    /**
     * Returns array combined by range of $min and $max for keys and for values
     *
     * @param integer $min
     * @param integer $max
     *
     * @return array
     */
    protected function getChoices($min, $max)
    {
        $range = range((int)$min, (int)$max);

        return array_combine($range, $range);
    }

    /**
     * Replace values form children to "choice" type with predefined choice list
     *
     * @param FormInterface $form
     * @param array         $choices
     */
    private function replaceValueFields(FormInterface $form, array $choices)
    {
        $children = array_keys($form->all());

        foreach ($children as $child) {
            $form->add($child, 'choice', ['choices' => $choices]);
        }
    }

    /**
     * Call callback for each of given value, used instead of array_map to walk safely through array
     *
     * @param array    $keys
     * @param array    $data
     * @param callable $callback
     */
    private function mapValues(array $keys, array &$data, \Closure $callback)
    {
        foreach ($keys as $key) {
            if (isset($data['value'], $data['value'][$key])) {
                $data['value'][$key] = $callback($data['value'][$key]);
            }
        }
    }

    /**
     * Returns callable that able to retrieve needed datePart from compiler result
     *
     * @param string $part
     *
     * @return callable
     */
    private function getDatePartAccessorClosure($part)
    {
        return function ($value) use ($part) {
            switch (true) {
                case is_numeric($value):
                    return (int)$value;
                    break;
                case ($value instanceof \DateTime):
                    return (int)$value->format($part);
                    break;
                default:
                    throw new UnexpectedTypeException($value, 'integer or \DateTime');
            }
        };
    }
}
