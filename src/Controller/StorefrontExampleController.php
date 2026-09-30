<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StorefrontExampleController extends StorefrontController
{
    #[Route(
        path: '/productreviewremindersw6/example', 
        name: 'frontend.productreviewremindersw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): Response
    {
        return $this->renderStorefront('@TopdataProductReviewReminderSW6/storefront/example.html.twig', [
            'pluginName' => 'ProductReviewReminderSW6'
        ]);
    }
}