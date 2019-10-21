<?php

declare(strict_types = 1);

namespace Drupal\oe_translation_poetry\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\oe_translation_poetry\Poetry;
use Drupal\oe_translation_poetry\PoetryJobQueue;
use Drupal\oe_translation_poetry_html_formatter\PoetryContentFormatterInterface;
use EC\Poetry\Messages\Responses\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handles the checkout form for Poetry requests.
 */
abstract class PoetryCheckoutFormBase extends FormBase {

  /**
   * The type of request (the product). Usually a translation request.
   *
   * @var string
   */
  protected $requestType = 'TRA';

  /**
   * The job queue.
   *
   * @var \Drupal\oe_translation_poetry\PoetryJobQueue
   */
  protected $queue;

  /**
   * The Poetry client.
   *
   * @var \Drupal\oe_translation_poetry\Poetry
   */
  protected $poetry;

  /**
   * The content formatter.
   *
   * @var \Drupal\oe_translation_poetry_html_formatter\PoetryContentFormatterInterface
   */
  protected $contentFormatter;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * PoetryCheckoutForm constructor.
   *
   * @param \Drupal\oe_translation_poetry\PoetryJobQueue $queue
   *   The job queue.
   * @param \Drupal\oe_translation_poetry\Poetry $poetry
   *   The Poetry client.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\oe_translation_poetry_html_formatter\PoetryContentFormatterInterface $contentFormatter
   *   The content formatter.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(PoetryJobQueue $queue, Poetry $poetry, MessengerInterface $messenger, PoetryContentFormatterInterface $contentFormatter, LoggerChannelFactoryInterface $loggerChannelFactory) {
    $this->queue = $queue;
    $this->poetry = $poetry;
    $this->messenger = $messenger;
    $this->contentFormatter = $contentFormatter;
    $this->logger = $loggerChannelFactory->get('oe_translation_poetry');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('oe_translation_poetry.job_queue'),
      $container->get('oe_translation_poetry.client.default'),
      $container->get('messenger'),
      $container->get('oe_translation_poetry.html_formatter'),
      $container->get('logger.factory')
    );
  }

  /**
   * The operation of the request: CREATE, UPDATE, DELETE.
   *
   * @return string
   *   The operation.
   */
  abstract protected function getRequestOperation(): string;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['details'] = [
      '#type' => 'details',
      '#title' => $this->t('Request details'),
      '#open' => TRUE,
    ];
    $form['details']['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Requested delivery date'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send request'),
      '#button_type' => 'primary',
      '#submit' => ['::submitRequest'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel and delete job'),
      '#button_type' => 'secondary',
      '#submit' => ['::cancelRequest'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // The submit handler is submitRequest().
  }

  /**
   * Cancels the request and deletes the jobs that had been created.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cancelRequest(array &$form, FormStateInterface $form_state): void {
    $this->cancelAndRedirect($form_state);
    $this->messenger->addStatus($this->t('The translation request has been cancelled and the corresponding jobs deleted.'));
  }

  /**
   * Deletes the jobs and redirects the user back.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function cancelAndRedirect(FormStateInterface $form_state): void {
    $this->redirectBack($form_state);
    $jobs = $this->queue->getAllJobs();
    foreach ($jobs as $job) {
      $job->delete();
    }
    $this->queue->reset();
  }

  /**
   * Sets the redirect back to the content onto the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function redirectBack(FormStateInterface $form_state): void {
    $destination = $this->queue->getDestination();
    if ($destination) {
      $form_state->setRedirectUrl($destination);
    }
  }

  /**
   * Creates the title of the request.
   *
   * It uses the configured prefix, site ID and the title of the Job (one of the
   * jobs as they are identical).
   *
   * @return string
   *   The title.
   */
  protected function createRequestTitle(): string {
    $jobs = $this->queue->getAllJobs();
    $job = reset($jobs);
    $settings = $this->poetry->getTranslatorSettings();
    return (string) new FormattableMarkup('@prefix: @site_id - @title', [
      '@prefix' => $settings['title_prefix'],
      '@site_id' => $settings['site_id'],
      '@title' => $job->label(),
    ]);
  }

  /**
   * Handles a response that comes from Poetry.
   *
   * @param \EC\Poetry\Messages\Responses\ResponseInterface $response
   *   The response.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function handlePoetryResponse(ResponseInterface $response, FormStateInterface $form_state): void {
    if (!$response->isSuccessful()) {
      $this->rejectJobs($response);
    }
    $jobs = $this->queue->getAllJobs();
    /** @var \EC\Poetry\Messages\Components\Identifier $identifier */
    $identifier = $response->getIdentifier();
    $identifier_values = [
      'code' => $identifier->getCode(),
      'year' => $identifier->getYear(),
      'number' => $identifier->getNumber(),
      'version' => $identifier->getVersion(),
      'part' => $identifier->getPart(),
      'product' => $identifier->getProduct(),
    ];
    $date = new \DateTime($form_state->getValue('details')['date']);
    foreach ($jobs as $job) {
      $job->set('poetry_request_id', $identifier_values);
      $job->set('poetry_request_date', $date->format('Y-m-d\TH:i:s'));
      // Submit the job. This will also save it.
      $job->submitted();
    }
  }

  /**
   * Rejects the jobs after a request failure.
   *
   * Sets the response warnings and error messages onto the jobs.
   *
   * @param \EC\Poetry\Messages\Responses\ResponseInterface $response
   *   The response.
   */
  protected function rejectJobs(ResponseInterface $response): void {
    $warnings = $response->getWarnings() ? implode('. ', $response->getWarnings()) : NULL;
    $errors = $response->getErrors() ? implode('. ', $response->getErrors()) : NULL;
    $job_ids = [];
    foreach ($this->queue->getAllJobs() as $job) {
      if ($warnings) {
        $job->addMessage(new FormattableMarkup('There were warnings with this request: @warnings', ['@warnings' => $warnings]));
      }
      if ($errors) {
        $job->addMessage(new FormattableMarkup('There were errors with this request: @errors', ['@errors' => $errors]));
      }
      $job->rejected();
      $job_ids[] = $job->id();
    }
    $message = new FormattableMarkup('The DGT request with the following jobs has been rejected upon submission: @jobs The messages have been saved in the jobs.', ['@jobs' => implode(', ', $job_ids)]);
    throw new \Exception($message->__toString());
  }

}
