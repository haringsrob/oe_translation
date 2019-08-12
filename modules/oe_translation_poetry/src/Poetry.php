<?php

declare(strict_types = 1);

namespace Drupal\oe_translation_poetry;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\State;
use Drupal\Core\Url;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\TranslatorInterface;
use EC\Poetry\Messages\Components\Identifier;
use EC\Poetry\Poetry as PoetryLibrary;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Poetry client.
 */
class Poetry extends PoetryLibrary {

  /**
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Poetry constructor.
   *
   * @param array $settings
   *   The translator config.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel
   *   The logger channel.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\State\State $state
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(array $settings, ConfigFactoryInterface $configFactory, LoggerChannelInterface $loggerChannel, LoggerInterface $logger, State $state, EntityTypeManagerInterface $entityTypeManager) {
    // @todo improve this in case we need alternative logging mechanisms.
    $loggerChannel->addLogger($logger);
    // Cannot rely on the translator getSetting() method because that might
    // instantiate the corresponding plugin which results in a circular
    // reference error.
    $values = [
      'identifier.code' => $settings['identifier_code'] ?? 'WEB',
      // The default version will always start from 0.
      'identifier.version' => 0,
      // The default part will always start from 0.
      'identifier.part' => 0,
      'identifier.sequence' => Settings::get('poetry.identifier.sequence'),
      'identifier.year' => date('Y'),
      'service.username' => Settings::get('poetry.service.username'),
      'service.password' => Settings::get('poetry.service.password'),
      'notification.username' => Settings::get('poetry.notification.username'),
      'notification.password' => Settings::get('poetry.notification.password'),
      'logger' => $loggerChannel,
      'log_level' => LogLevel::INFO,
    ];

    if (isset($settings['service_wsdl'])) {
      $values['service.wsdl'] = $settings['service_wsdl'];
    }

    parent::__construct($values);
    $this->state = $state;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Gets the global identification number.
   *
   * @return int
   */
  public function getGlobalIdentifierNumber() {
    return $this->state->get('oe_translation_poetry_id_number');
  }

  /**
   * Sets the global identification number.
   */
  public function setGlobalIdentifierNumber(int $number) {
    $this->state->set('oe_translation_poetry_id_number', $number);
  }

  /**
   * Returns the identifier for making a translation request for a content.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return \EC\Poetry\Messages\Components\Identifier|null
   */
  public function getIdentifierForContent(ContentEntityInterface $entity): Identifier {
    $last_identifier_for_content = $this->getLastIdentifierForContent($entity);
    if ($last_identifier_for_content instanceof Identifier) {
      // If the content has already been translated, we need to use the
      // identifier it had and just increase the version. This means potentially
      // even an older number than the most current global one as well as an
      // older year.
      $last_identifier_for_content->setVersion($last_identifier_for_content->getVersion() + 1);
      return $last_identifier_for_content;
    }

    $identifier = $this->getIdentifier();
    $number = $this->getGlobalIdentifierNumber();

    if (!$number) {
      // If we don't have a number it means it's the first ever request.
      $identifier->setSequence(Settings::get('poetry.identifier.sequence'));
      return $identifier;
    }

    // If we have a global number, we can maybe use it. However, we first to
    // determine the part. And for this we need to check the jobs.
    $part = $this->getLastPartForNumber($number);
    if ($part > 0) {
      // We check if the part came back as 0 in case jobs were missing from
      // the system, we increment only if we know where to increment from.
      $part++;
    }

    // If the incremented part is 100, we need to scrap the the global number
    // and request a new one. The maximum can be 99.
    if ($part === 100) {
      $identifier->setSequence(Settings::get('poetry.identifier.sequence'));
      return $identifier;
    }

    $identifier->setNumber($number);
    $identifier->setPart($part);

    return $identifier;
  }

  /**
   * Locates the last identifier that was used for a given content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return \EC\Poetry\Messages\Components\Identifier|null
   */
  protected function getLastIdentifierForContent(ContentEntityInterface $entity) {
    $query = \Drupal::database()->select('tmgmt_job', 'job');
    $query->join('tmgmt_job_item', 'job_item', 'job.tjid = job_item.tjid');
    $query->fields('job');
    $query->condition('job_item.item_id', $entity->id());
    // Do not include unprocessed Jobs. These are the ones which have not been
    // ever sent to Poetry.
    $query->condition('job.state', Job::STATE_UNPROCESSED, '!=');
    $query->orderBy('job.poetry_request_id__version', 'DESC');
    $result = $query->execute()->fetchCol('poetry_request_id__version');
    if (!$result) {
      return NULL;
    }

    $job = $this->entityTypeManager->getStorage('tmgmt_job')->load(reset($result));

    $identifier = new Identifier();
    $identifier->withArray($job->get('poetry_request_id')->first()->getValue());
    return $identifier;
  }

  /**
   * Gets the next part to use for a global number.
   *
   * @param $number
   *
   * @return int
   */
  protected function getLastPartForNumber($number) {
    $job_ids = $this->entityTypeManager->getStorage('tmgmt_job')->getQuery()
      ->condition('poetry_request_id__number', $number)
      ->sort('poetry_request_id.part', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$job_ids) {
      // Normally we should get a value since the number must have been used
      // on previous jobs.
      return 0;
    }

    /** @var \Drupal\tmgmt\JobInterface $job */
    $job = $this->entityTypeManager->getStorage('tmgmt_job')->load(reset($job_ids));
    return (int) $job->get('poetry_request_id')->part;
  }

}
