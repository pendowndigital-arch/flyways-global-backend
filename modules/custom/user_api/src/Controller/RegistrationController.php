<?php

namespace Drupal\user_api\Controller;

use Drupal\Core\Flood\FloodInterface;
use Drupal\user\UserInterface;
use Drupal\user_api\Exception\ApiException;
use Drupal\user_api\Service\AccountActivation;
use Drupal\user_api\Service\UsernameGenerator;
use Drupal\user_api\Service\UserNormalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Registration and email activation endpoints.
 */
class RegistrationController extends ApiControllerBase {

  /**
   * Flood event name for registration attempts.
   */
  const FLOOD_REGISTER = 'user_api.register';

  public function __construct(
    protected AccountActivation $accountActivation,
    protected UserNormalizer $userNormalizer,
    protected FloodInterface $flood,
    protected UsernameGenerator $usernameGenerator,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_api.account_activation'),
      $container->get('user_api.user_normalizer'),
      $container->get('flood'),
      $container->get('user_api.username_generator')
    );
  }

  /**
   * Registers a new, inactive account and emails an activation link.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function register(Request $request): JsonResponse {
    return $this->handle(fn() => $this->doRegister($request));
  }

  /**
   * Activates an account from an emailed link.
   *
   * @param string $token
   *   The activation token.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function activateFromLink(string $token): JsonResponse {
    return $this->handle(fn() => $this->doActivate($token));
  }

  /**
   * Activates an account from a token posted by a client.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function activate(Request $request): JsonResponse {
    return $this->handle(function () use ($request) {
      $data = $this->decodeBody($request);

      return $this->doActivate($this->readString($data, 'token'));
    });
  }

  /**
   * Re-sends the activation email for a pending account.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  public function resend(Request $request): JsonResponse {
    return $this->handle(function () use ($request) {
      $data = $this->decodeBody($request);
      $mail = $this->readString($data, 'mail', 'email');

      if ($mail === '') {
        throw new ApiException('The submitted values are not valid.', 'validation_failed', 422, [
          'mail' => ['An email address is required.'],
        ]);
      }

      $this->guardFlood($request);
      $this->flood->register(self::FLOOD_REGISTER, $this->floodWindow(), $request->getClientIp());
      $this->accountActivation->resend($mail);

      // Answer identically whether or not the address is registered, so this
      // endpoint cannot be used to discover which addresses have accounts.
      return $this->success('If that address belongs to an account awaiting activation, a new activation email is on its way.');
    });
  }

  /**
   * Creates the account and starts the activation flow.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If registration is closed, flooded or the values are invalid.
   */
  protected function doRegister(Request $request): JsonResponse {
    if ($this->config('user.settings')->get('register') === UserInterface::REGISTER_ADMINISTRATORS_ONLY) {
      throw new ApiException('Public registration is disabled on this site.', 'registration_disabled', 403);
    }

    $this->guardFlood($request);

    $data = $this->decodeBody($request);
    $name = $this->readString($data, 'name', 'username');
    $mail = $this->readString($data, 'mail', 'email');
    $password = isset($data['password']) && is_scalar($data['password'])
      ? (string) $data['password']
      : (string) ($data['pass'] ?? '');

    $missing = [];
    if ($mail === '') {
      $missing['mail'] = ['An email address is required.'];
    }
    if ($password === '') {
      $missing['password'] = ['A password is required.'];
    }
    if ($missing) {
      throw new ApiException('The submitted values are not valid.', 'validation_failed', 422, $missing);
    }

    // Clients sign up with an email address alone, so the username is derived
    // from it. An explicitly supplied name is kept and validated as given.
    if ($name === '') {
      $name = $this->usernameGenerator->fromEmail($mail);
    }

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager()->getStorage('user')->create([
      'name' => $name,
      'mail' => $mail,
      'pass' => $password,
      // The account stays blocked until the emailed token is redeemed; this is
      // what makes the confirmation step meaningful.
      'status' => 0,
      // Mirrors core, which records the address the account was created with.
      'init' => $mail,
    ]);

    foreach ($this->registrationFields($data) as $field_name => $value) {
      $account->set($field_name, $value);
    }

    // Entity validation covers uniqueness of the name and address, address
    // syntax and any password policy constraints the site has added.
    $this->assertNoViolations($account->validate());
    $account->save();

    $this->flood->register(
      self::FLOOD_REGISTER,
      $this->floodWindow(),
      $request->getClientIp()
    );

    $mail_sent = $this->accountActivation->start($account);

    return $this->success(
      $mail_sent
        ? 'Your account has been created. Check your email for the activation link to finish signing up.'
        : 'Your account has been created, but the activation email could not be sent. Please request a new one.',
      [
        'user' => $this->userNormalizer->normalize($account),
        'activation_email_sent' => $mail_sent,
      ],
      201
    );
  }

  /**
   * Redeems an activation token.
   *
   * @param string $token
   *   The activation token.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function doActivate(string $token): JsonResponse {
    $account = $this->accountActivation->activate($token);
    $active = $account->isActive();

    return $this->success(
      $active
        ? 'Your account is now active. You can log in.'
        : 'Your email address is confirmed. An administrator still has to approve the account before you can log in.',
      [
        'user' => $this->userNormalizer->normalize($account),
        'email_confirmed' => TRUE,
        'can_log_in' => $active,
      ]
    );
  }

  /**
   * Collects the optional extra fields accepted at registration.
   *
   * @param array $data
   *   The decoded payload.
   *
   * @return array
   *   Field values keyed by field name.
   */
  protected function registrationFields(array $data): array {
    $values = [];
    $editable = $this->config('user_api.settings')->get('editable_fields');

    foreach (is_array($editable) ? $editable : [] as $field_name) {
      if (array_key_exists($field_name, $data) && is_scalar($data[$field_name])) {
        $values[$field_name] = $data[$field_name];
      }
    }

    return $values;
  }

  /**
   * Rejects the request if the caller's IP has tripped the flood limit.
   *
   * Registration and resend both send email, so they are rate limited to keep
   * the site from being used as a mail relay.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @throws \Drupal\user_api\Exception\ApiException
   *   If the limit has been reached.
   */
  protected function guardFlood(Request $request): void {
    $limit = (int) $this->config('user_api.settings')->get('register_flood_limit');
    if ($limit < 1) {
      return;
    }

    if (!$this->flood->isAllowed(self::FLOOD_REGISTER, $limit, $this->floodWindow(), $request->getClientIp())) {
      throw new ApiException(
        'Too many registration attempts from your IP address. Please try again later.',
        'flood_register',
        429
      );
    }
  }

  /**
   * Gets the registration flood window.
   *
   * @return int
   *   The window in seconds.
   */
  protected function floodWindow(): int {
    $window = (int) $this->config('user_api.settings')->get('register_flood_window');

    return $window > 0 ? $window : 3600;
  }

}
