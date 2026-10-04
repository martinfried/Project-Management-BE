<?php

namespace App\Controller;

use App\Entity\Person;
use App\Repository\PersonRepository;
use App\Repository\ProjectRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/persons')]
#[OA\Tag(name: 'Persons', description: 'Management of persons and their assignments to teams and projects')]
class PersonController extends BaseApiController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PersonRepository $personRepository,
        private ProjectRepository $projectRepository,
        private TeamRepository $teamRepository,
        ValidatorInterface $validator
    ) {
        parent::__construct($validator);
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/persons',
        summary: 'List persons',
        description: 'Returns a list of persons with optional filtering by name/email, role, or team ID'
    )]
    #[OA\Parameter(name: 'search', in: 'query', description: 'Search in name, email, and role', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'role', in: 'query', description: 'Filter by exact role', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'teamId', in: 'query', description: 'Filter by team ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'List of persons',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponsePersonList')
    )]
    public function index(Request $request): JsonResponse
    {
        $search = $request->query->get('search');
        $role = $request->query->get('role');
        $teamId = $request->query->get('teamId');

        $qb = $this->personRepository->createQueryBuilder('p')
            ->leftJoin('p.team', 't')
            ->addSelect('t')
            ->orderBy('p.name', 'ASC');

        if ($search) {
            $qb->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.email) LIKE :search OR LOWER(p.role) LIKE :search')
                ->setParameter('search', '%' . strtolower(trim($search)) . '%');
        }

        if ($role) {
            $qb->andWhere('p.role = :role')
                ->setParameter('role', trim($role));
        }

        if ($teamId) {
            $qb->andWhere('p.team = :teamId')
                ->setParameter('teamId', (int) $teamId);
        }

        $persons = $qb->getQuery()->getResult();
        $data = array_map(fn(Person $person) => $person->toArray(false), $persons);

        return $this->listResponse($data);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/persons/{id}',
        summary: 'Get person details',
        description: 'Returns details of a person including assigned team and participating projects'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Person ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Person details',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponsePersonDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Person not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function show(int $id): JsonResponse
    {
        $person = $this->personRepository->find($id);
        if (!$person) {
            return $this->notFoundResponse('Person not found');
        }

        return $this->successResponse($person->toArray(true));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        path: '/api/persons',
        summary: 'Create person',
        description: 'Creates a new person profile with optional team and project assignments'
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PersonInput'))]
    #[OA\Response(
        response: 201,
        description: 'Person was successfully created',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponsePersonDetail')
    )]
    #[OA\Response(
        response: 400,
        description: 'Validation error',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $person = new Person();
        $this->mapPayloadToPerson($person, $payload);

        if ($validationError = $this->validateEntity($person)) {
            return $validationError;
        }

        if (isset($payload['projectIds']) && is_array($payload['projectIds'])) {
            $this->syncProjects($person, $payload['projectIds']);
        }

        $this->em->persist($person);
        $this->em->flush();

        return $this->successResponse(
            $person->toArray(true),
            'Person was successfully created',
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/persons/{id}',
        summary: 'Update person',
        description: 'Updates an existing person profile'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Person ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PersonInput'))]
    #[OA\Response(
        response: 200,
        description: 'Person was successfully updated',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponsePersonDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Person not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function update(int $id, Request $request): JsonResponse
    {
        $person = $this->personRepository->find($id);
        if (!$person) {
            return $this->notFoundResponse('Person not found');
        }

        $payload = $this->getPayload($request);
        $this->mapPayloadToPerson($person, $payload);

        if ($validationError = $this->validateEntity($person)) {
            return $validationError;
        }

        if (array_key_exists('projectIds', $payload) && is_array($payload['projectIds'])) {
            $this->syncProjects($person, $payload['projectIds']);
        }

        $this->em->flush();

        return $this->successResponse(
            $person->toArray(true),
            'Person was successfully updated'
        );
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/persons/{id}',
        summary: 'Delete person',
        description: 'Removes a person from the system'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Person ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Person was successfully deleted',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseSuccessMessage')
    )]
    #[OA\Response(
        response: 404,
        description: 'Person not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function delete(int $id): JsonResponse
    {
        $person = $this->personRepository->find($id);
        if (!$person) {
            return $this->notFoundResponse('Person not found');
        }

        // Clean up project relations safely
        foreach ($person->getProjects() as $project) {
            $project->removePerson($person);
        }

        $this->em->remove($person);
        $this->em->flush();

        return $this->successResponse(null, 'Person was successfully deleted');
    }

    private function mapPayloadToPerson(Person $person, array $payload): void
    {
        if (isset($payload['name'])) {
            $person->setName((string) $payload['name']);
        }
        if (isset($payload['email'])) {
            $person->setEmail((string) $payload['email']);
        }
        if (isset($payload['role'])) {
            $person->setRole((string) $payload['role']);
        }
        if (array_key_exists('teamId', $payload)) {
            $team = !empty($payload['teamId']) ? $this->teamRepository->find($payload['teamId']) : null;
            $person->setTeam($team);
        }
    }

    private function syncProjects(Person $person, array $projectIds): void
    {
        foreach ($person->getProjects()->toArray() as $project) {
            $person->removeProject($project);
        }
        foreach ($projectIds as $projectId) {
            $project = $this->projectRepository->find($projectId);
            if ($project) {
                $person->addProject($project);
            }
        }
    }
}
