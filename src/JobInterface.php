<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use MongoDB\BSON\ObjectId;

interface JobInterface
{
    /**
     * Job status.
     */
    public const STATUS_WAITING = 0;
    public const STATUS_POSTPONED = 1;
    public const STATUS_PROCESSING = 2;
    public const STATUS_DONE = 3;
    public const STATUS_FAILED = 4;
    public const STATUS_CANCELED = 5;
    public const STATUS_KILLED = 6;
    public const STATUS_TIMEOUT = 7;

    /**
     * Get job data.
     */
    public function setData($data): self;

    /**
     * Get job data.
     */
    public function getData();

    /**
     * Set ID.
     */
    public function setId(ObjectId $id): self;

    /**
     * Get ID.
     */
    public function getId(): ObjectId;

    /**
     * Start job.
     */
    public function start(): bool;
}
