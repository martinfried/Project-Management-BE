<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class BaseApiController extends AbstractController
{
    public function __construct(
        protected ValidatorInterface $validator
    ) {
    }

    /**
     * Parse JSON request content into an associative array.
     */
    protected function getPayload(Request $request): array
    {
        $content = $request->getContent();
        if (empty($content)) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Validate an entity using Symfony Validator.
     * Returns a 400 Bad Request JsonResponse on failure, or null on success.
     */
    protected function validateEntity(object $entity): ?JsonResponse
    {
        $errors = $this->validator->validate($entity);
        if (count($errors) === 0) {
            return null;
        }

        $errorMessages = [];
        foreach ($errors as $error) {
            $errorMessages[$error->getPropertyPath()] = $error->getMessage();
        }

        return $this->json([
            'success' => false,
            'errors' => $errorMessages,
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Standard success response with optional payload and message.
     */
    protected function successResponse(mixed $data = null, ?string $message = null, int $statusCode = Response::HTTP_OK): JsonResponse
    {
        $response = ['success' => true];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        return $this->json($response, $statusCode);
    }

    /**
     * Standard list response with item count.
     */
    protected function listResponse(array $items): JsonResponse
    {
        return $this->json([
            'success' => true,
            'count' => count($items),
            'data' => $items,
        ]);
    }

    /**
     * Standard 404 Not Found response.
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
        ], Response::HTTP_NOT_FOUND);
    }
}
