<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Model;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadEventLogRepository;

final class ExternalActivityModel
{
    public const EVENT_TYPE = 'lead.external';
    public const OBJECT     = 'external_activity';

    public function __construct(
        private LeadEventLogRepository $repository,
        private UserHelper $userHelper,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(Lead $contact, string $type, array $data): LeadEventLog
    {
        $user = $this->userHelper->getUser();
        $log  = new LeadEventLog();
        $log->setLead($contact)
            ->setBundle('lead')
            ->setObject(self::OBJECT)
            ->setAction(self::OBJECT.'.'.$type)
            ->setUserId($user->getId())
            ->setUserName($user->getUserIdentifier())
            ->setProperties(['type' => $type, 'data' => $data]);

        $this->repository->saveEntity($log);

        return $log;
    }
}
