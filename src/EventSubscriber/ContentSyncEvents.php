<?php

namespace Drupal\content_sync\EventSubscriber;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityTypeEvents;
use Drupal\Core\Entity\EntityTypeEvent;

/**
 * Create a content subscriber.
 */
class ContentSyncEvents implements EventSubscriberInterface {

  use AutowireTrait;

  /**
   * Constructor.
   */
  public function __construct(
    #[Autowire(service: 'logger.channel.content_sync')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Event callback for EntityTypeEvents::CREATE events.
   *
   * @param \Drupal\Core\Entity\EntityTypeEvent $event
   *   The event to process.
   */
  public function onContentSyncCreate(EntityTypeEvent $event) : void {
    $this->logger->notice("Create Event", ['event' => $event]);
  }

  /**
   * Registers the methods in this class that should be listeners.
   *
   * @return array
   *   An array of event listener definitions.
   */
  public static function getSubscribedEvents() : array {
    $events[EntityTypeEvents::CREATE][] = ['onContentSyncCreate', 40];
    return $events;
  }

}
