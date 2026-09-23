<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\EventListener;

use Mautic\LeadBundle\Entity\LeadEventLogRepository;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\ExternalActivityModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExternalActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LeadEventLogRepository $repository,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LeadEvents::TIMELINE_ON_GENERATE => 'onTimelineGenerate'];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        $eventType = ExternalActivityModel::EVENT_TYPE;
        $label     = $this->translator->trans('mautic.lead.timeline.external_activity');
        $event->addEventType($eventType, $label);

        if (!$event->isApplicable($eventType)) {
            return;
        }

        $logs = $this->repository->getEvents($event->getLead(), 'lead', ExternalActivityModel::OBJECT, null, $event->getQueryOptions());
        $event->addToCounter($eventType, $logs);

        if ($event->isEngagementCount()) {
            return;
        }

        foreach ($logs['results'] as $log) {
            $properties = json_decode($log['properties'], true, 512, JSON_THROW_ON_ERROR);
            $event->addEvent([
                'event'           => $eventType,
                'eventId'         => $eventType.$log['id'],
                'eventLabel'      => $properties['type'],
                'eventType'       => $label,
                'timestamp'       => $log['date_added'],
                'contactId'       => $log['lead_id'],
                'icon'            => 'ri-external-link-line',
                'extra'           => $properties,
                'contentTemplate' => '@MauticLead/Timeline/external_activity.html.twig',
            ]);
        }
    }
}
