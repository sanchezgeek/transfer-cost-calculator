<?php

namespace App\Controller\Api;

use App\Api\Exception\BadRequestException;
use App\Api\Request\DataFilterTrait;
use App\Api\Response\SuccessResponseDto;
use App\Delivery\Application\Commands\CreateOrderDeliveryCommand;
use App\Delivery\Application\Services\Geo\GeoObjectProvider;
use App\Delivery\Domain\DeliveryRepository;
use App\Delivery\Domain\Exception\OrderDeliveryAlreadyExists;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\NotBlank;
use Throwable;

class DeliveryController
{
    use DataFilterTrait;
    use DispatchCommandTrait;

    public function __construct(
        private readonly DeliveryRepository $deliveryRepository,
        private readonly GeoObjectProvider $geoObjectProvider,
        MessageBusInterface $commandBus
    ) {
        $this->commandBus = $commandBus;
    }

    /**
     * @Route("/api/delivery-order-create", methods={"POST"}, name="delivery-order-create")
     *
     * @param Request $request
     * @return JsonResponse
     * @throws BadRequestException|Throwable
     */
    public function create(Request $request): JsonResponse
    {
        [$orderId, $address] = self::filterData([
            'order_id' => [new NotBlank(), new Type('int')],
            'address' => [new NotBlank(), new Type('string')],
        ], $request->toArray());

        if (!$this->geoObjectProvider->findGeoObject($address)) {
            throw BadRequestException::error(
                \sprintf('Cannot find address `%s` to calculate distance', $address),
                'address'
            );
        }

        $deliveryId = $this->deliveryRepository->getNextId();

        try {
            $this->dispatchCommand(new CreateOrderDeliveryCommand($deliveryId, $orderId, $address));
        } catch (OrderDeliveryAlreadyExists $e) {
            throw BadRequestException::errors([
                [
                    'field' => 'order_id',
                    'message' => $e->getMessage(),
                    'payload' => ['deliveryId' => $e->deliveryId],
                ],
            ]);
        }

        return new JsonResponse(
            new SuccessResponseDto(['deliveryId' => $deliveryId])
        );
    }
}
