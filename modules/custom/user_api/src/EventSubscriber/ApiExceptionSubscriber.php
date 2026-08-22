<?php

namespace Drupal\user_api\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders errors on user API routes as JSON instead of an HTML page.
 *
 * Access and method failures are raised by the kernel before a controller
 * runs, so without this an API client asking for /api/user/me with no token
 * would receive a full themed HTML 403 page.
 */
class ApiExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Route name prefix owned by this module.
   */
  const ROUTE_PREFIX = 'user_api.';

  /**
   * Path prefix owned by this module.
   */
  const PATH_PREFIX = '/api/user';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Runs ahead of the core HTML and generic exception subscribers so the
    // response is already set by the time they look at the event.
    return [KernelEvents::EXCEPTION => [['onException', 100]]];
  }

  /**
   * Converts an exception on a user API route into a JSON response.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!$this->isApiRequest($request)) {
      return;
    }

    $exception = $event->getThrowable();
    if (!$exception instanceof HttpExceptionInterface) {
      // Let core handle unexpected errors, so that logging, backtraces and the
      // site's error display settings all behave as an administrator expects.
      return;
    }

    $status = $exception->getStatusCode();
    $headers = $exception->getHeaders();
    $code = match ($status) {
      401 => 'unauthenticated',
      403 => 'access_denied',
      404 => 'not_found',
      405 => 'method_not_allowed',
      default => 'error',
    };

    // A missing credential is an authentication problem, not an authorisation
    // one: answering 401 tells the client to obtain a token rather than
    // suggesting the token it holds is not good enough.
    if ($status === 403 && !$request->headers->has('Authorization')) {
      $status = 401;
      $code = 'unauthenticated';
      $headers['WWW-Authenticate'] = 'Bearer realm="user_api"';
    }

    $message = $exception->getMessage() !== ''
      ? $exception->getMessage()
      : 'The request could not be completed.';

    if ($code === 'unauthenticated') {
      $message = 'Authentication required. Send a valid OAuth2 bearer token in the Authorization header.';
    }

    $response = new JsonResponse(
      ['error' => ['code' => $code, 'message' => $message]],
      $status,
      $headers
    );

    $event->setResponse($response);
    $event->stopPropagation();
  }

  /**
   * Checks whether a request targets this module's API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE if the request belongs to the user API.
   */
  protected function isApiRequest(Request $request): bool {
    $route_name = (string) $request->attributes->get('_route');
    if ($route_name !== '') {
      return str_starts_with($route_name, self::ROUTE_PREFIX);
    }

    // No route matched, e.g. a 404 or a rejected method, so fall back to the
    // path the client asked for.
    return str_starts_with($request->getPathInfo(), self::PATH_PREFIX);
  }

}
