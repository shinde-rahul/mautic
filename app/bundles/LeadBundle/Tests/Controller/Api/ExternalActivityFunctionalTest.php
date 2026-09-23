<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Controller\Api;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Model\ExternalActivityModel;
use Mautic\LeadBundle\Model\LeadModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class ExternalActivityFunctionalTest extends MauticMysqlTestCase
{
    use ApiTestUserTrait;

    public function testRecordExternalActivity(): void
    {
        $contact = $this->createContact();
        $data    = ['product_name' => 'Aivie Pro', 'quantity' => 2, 'metadata' => ['paid' => true], 'note' => '<script>alert(1)</script>'];

        $this->postActivity($contact->getId(), ['type' => 'purchase', 'data' => $data]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $this->client->getResponse()->getContent());
        $created = json_decode($this->client->getResponse()->getContent(), true)['activity'];
        self::assertSame('purchase', $created['type']);
        self::assertSame($contact->getId(), $created['contactId']);
        self::assertSame($data, $created['data']);

        $stored = $this->em->getRepository(LeadEventLog::class)->find($created['id']);
        self::assertSame(['type' => 'purchase', 'data' => $data], $stored->getProperties());
    }

    public function testReadAndRenderExternalActivity(): void
    {
        $contact = $this->createContact();
        $other   = $this->createContact();
        $data    = ['product_name' => 'Aivie Pro', 'quantity' => 2, 'metadata' => ['paid' => true], 'note' => '<script>alert(1)</script>'];

        $this->postActivity($contact->getId(), ['type' => 'purchase', 'data' => $data]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $this->client->getResponse()->getContent());
        foreach (['/api/contacts/'.$contact->getId().'/activity', '/api/contacts/activity'] as $url) {
            $this->client->request('GET', $url, ['filters' => ['includeEvents' => [ExternalActivityModel::EVENT_TYPE]]]);
            self::assertResponseIsSuccessful();
            $payload = json_decode($this->client->getResponse()->getContent(), true);
            self::assertSame(1, $payload['total']);
            self::assertSame('purchase', $payload['events'][0]['eventLabel']);
            self::assertSame($data, $payload['events'][0]['details']['data']);
        }

        $this->client->request('GET', '/api/contacts/'.$other->getId().'/activity', ['filters' => ['includeEvents' => [ExternalActivityModel::EVENT_TYPE]]]);
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['total']);

        $timeline = static::getContainer()->get(LeadModel::class)->getEngagements($contact, ['includeEvents' => [ExternalActivityModel::EVENT_TYPE], 'excludeEvents' => [], 'search' => '']);
        $event    = $timeline['events'][0];
        $html     = static::getContainer()->get(Environment::class)->render($event['contentTemplate'], ['event' => $event]);
        self::assertStringContainsString('Aivie Pro', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPaginationSearchAndExclusion(): void
    {
        $contact = $this->createContact();
        foreach (['purchase', 'pageview', 'created_contact'] as $type) {
            $this->postActivity($contact->getId(), ['type' => $type, 'data' => ['product' => $type]]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        $url     = '/api/contacts/'.$contact->getId().'/activity';
        $filters = ['includeEvents' => [ExternalActivityModel::EVENT_TYPE]];
        $this->client->request('GET', $url, ['filters' => $filters, 'limit' => 1]);
        $first = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(3, $first['total']);
        self::assertCount(1, $first['events']);
        $this->client->request('GET', $url, ['filters' => $filters, 'limit' => 1, 'page' => 2]);
        $second = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotSame($first['events'][0]['eventId'], $second['events'][0]['eventId']);

        $this->client->request('GET', $url, ['filters' => $filters, 'limit' => 1, 'order' => ['eventLabel', 'ASC']]);
        self::assertSame('created_contact', json_decode($this->client->getResponse()->getContent(), true)['events'][0]['eventLabel']);

        $this->client->request('GET', $url, ['filters' => $filters + ['search' => 'purchase']]);
        self::assertSame(1, json_decode($this->client->getResponse()->getContent(), true)['total']);

        $this->client->request('GET', $url, ['filters' => ['excludeEvents' => [ExternalActivityModel::EVENT_TYPE]]]);
        $events = json_decode($this->client->getResponse()->getContent(), true)['events'];
        self::assertNotContains(ExternalActivityModel::EVENT_TYPE, array_column($events, 'event'));
        self::assertNotContains('lead.source.created', array_column($events, 'event'));
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectInvalidPayload(array $payload): void
    {
        $contact = $this->createContact();
        $this->postActivity($contact->getId(), $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(LeadEventLog::class)->count(['object' => ExternalActivityModel::OBJECT]));
    }

    public static function invalidPayloads(): iterable
    {
        yield 'missing type' => [['data' => []]];
        yield 'empty type' => [['type' => '', 'data' => []]];
        yield 'non-string type' => [['type' => [], 'data' => []]];
        yield 'unsafe type' => [['type' => '<script>', 'data' => []]];
        yield 'long type' => [['type' => str_repeat('a', 65), 'data' => []]];
        yield 'missing data' => [['type' => 'purchase']];
        yield 'scalar data' => [['type' => 'purchase', 'data' => 'product']];
        yield 'list data' => [['type' => 'purchase', 'data' => ['product']]];
    }

    public function testMissingContact(): void
    {
        $this->postActivity(2147483647, ['type' => 'purchase', 'data' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testViewOnlyUserCannotRecordActivity(): void
    {
        $contact = $this->createContact();
        $user    = $this->createApiUserWithPermissions(['lead:leads' => ['viewown', 'viewother']]);
        $this->authenticateApiUser($user);
        $this->postActivity($contact->getId(), ['type' => 'purchase', 'data' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->em->getRepository(LeadEventLog::class)->count(['object' => ExternalActivityModel::OBJECT]));
    }

    public function testContactEditPermissionIsRequired(): void
    {
        $user = $this->createApiUserWithPermissions(['lead:leads' => ['viewown', 'viewother', 'editown']]);
        $own  = $this->createContact();
        $own->setOwner($user);
        $this->em->flush();
        $other = $this->createContact();
        $this->authenticateApiUser($user);

        $this->postActivity($own->getId(), ['type' => 'purchase', 'data' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postActivity($other->getId(), ['type' => 'purchase', 'data' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $this->em->getRepository(LeadEventLog::class)->count(['object' => ExternalActivityModel::OBJECT]));
    }

    private function createContact(): Lead
    {
        $contact = new Lead();
        $contact->setEmail(uniqid('external-').'@example.com');
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function postActivity(int $contactId, array $payload): void
    {
        $this->client->request('POST', '/api/contacts/'.$contactId.'/activity/new', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
