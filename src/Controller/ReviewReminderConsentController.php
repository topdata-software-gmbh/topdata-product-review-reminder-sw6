<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Controller;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataProductReviewReminderSW6\Service\ReviewReminderConsentService;

/**
 * Reads and writes the customer's opt-in for review reminders.
 *
 * Modelled on core's NewsletterController: same route shape, same login
 * requirement, same background submit. The response differs on purpose — JSON
 * instead of a re-rendered form fragment, because the form's onchange handler
 * applies the answer itself instead of depending on an ajax swap.
 *
 * The `XmlHttpRequest => true` default is mandatory, not decoration. Without it
 * StorefrontSubscriber::preventPageLoadingFromXmlHttpRequest() rejects every
 * background submit with HTTP 403 before the controller runs, so the setting
 * silently never persists.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
#[Package('after-sales')]
class ReviewReminderConsentController extends StorefrontController
{
    final public const OPTION_SUBSCRIBE = 'subscribe';

    public function __construct(
        private readonly ReviewReminderConsentService $consentService,
    ) {
    }

    #[Route(
        path: '/widgets/account/review-reminder-consent',
        name: 'account.reviewReminder.consent',
        defaults: [
            'XmlHttpRequest' => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
        ],
        methods: [Request::METHOD_POST]
    )]
    public function save(Request $request, RequestDataBag $dataBag, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        // A checkbox contributes nothing to the POST body when it is UNCHECKED,
        // so a missing `option` means "withdraw". Mapping absence to subscribe
        // instead would make un-ticking the box opt the customer IN.
        $active = $dataBag->get('option') === self::OPTION_SUBSCRIBE;

        if ($active) {
            $this->consentService->grant($customer->getId(), $context->getContext());
        } else {
            $this->consentService->revoke($customer->getId(), $context->getContext());
        }

        if ($request->isXmlHttpRequest()) {
            // Read the state back instead of echoing the request, so the checkbox
            // always shows what the database holds.
            return new JsonResponse([
                'active' => $this->consentService->isActive($customer->getId(), $context->getContext()),
            ]);
        }

        // Native post from the <noscript> button.
        return $this->redirectToRoute('frontend.account.home.page');
    }
}
