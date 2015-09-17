<?php

namespace Oro\Bundle\SearchBundle\Event;

use Symfony\Component\EventDispatcher\Event;

use Oro\Bundle\SearchBundle\Query\Result;

class AfterSearchEvent extends Event
{
    const EVENT_NAME = "oro_search.after_search";

    /** @var Result $result */
    protected $result;

    /**
     * @param Result $result
     */
    public function __construct($result)
    {
        $this->result = $result;
    }

    /**
     * @return Result
     */
    public function getResult()
    {
        return $this->result;
    }
}
