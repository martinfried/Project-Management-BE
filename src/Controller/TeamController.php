<?php

namespace App\Controller;

use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/teams')]
#[OA\Tag(name: 'Teams', description: 'Management of working teams and their members')]
class TeamController extends BaseApiController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TeamRepository $teamRepository,
        private PersonRepository $personRepository,
        ValidatorInterface $validator
    ) {
        parent::__construct($validator);
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/teams',
        summary: 'List teams',
        description: 'Returns a list of all teams with optional search in name and description'
    )]
    #[OA\Parameter(name: 'search', in: 'query', description: 'Search in team name and description', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(
        response: 200,
        description: 'List of teams',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseTeamList')
    )]
    public function index(Request $request): JsonResponse
    {
        $search = $request->query->get('search');

        $qb = $this->teamRepository->createQueryBuilder('t')
            ->leftJoin('t.members', 'm')
            ->leftJoin('t.projects', 'p')
            ->addSelect('m', 'p')
            ->orderBy('t.name', 'ASC');

        if ($search) {
            $qb->andWhere('LOWER(t.name) LIKE :search OR LOWER(t.description) LIKE :search')
                ->setParameter('search', '%' . strtolower(trim($search)) . '%');
        }

        $teams = $qb->getQuery()->getResult();
        $data = array_map(fn(Team $team) => $team->toArray(false), $teams);

        return $this->listResponse($data);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/teams/{id}',
        summary: 'Get team details',
        description: 'Returns team details including list of members and assigned projects'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Team ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Team details',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseTeamDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Team not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function show(int $id): JsonResponse
    {
        $team = $this->teamRepository->find($id);
        if (!$team) {
            return $this->notFoundResponse('Team not found');
        }

        return $this->successResponse($team->toArray(true));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        path: '/api/teams',
        summary: 'Create team',
        description: 'Creates a new team with optional initial member assignments'
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TeamInput'))]
    #[OA\Response(
        response: 201,
        description: 'Team was successfully created',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseTeamDetail')
    )]
    #[OA\Response(
        response: 400,
        description: 'Validation error',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $team = new Team();
        $this->mapPayloadToTeam($team, $payload);

        if ($validationError = $this->validateEntity($team)) {
            return $validationError;
        }

        $this->em->persist($team);
        $this->em->flush();

        if (isset($payload['memberIds']) && is_array($payload['memberIds'])) {
            $this->syncMembers($team, $payload['memberIds']);
            $this->em->flush();
        }

        return $this->successResponse(
            $team->toArray(true),
            'Team was successfully created',
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/teams/{id}',
        summary: 'Update team',
        description: 'Updates team name, description, or member assignments'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Team ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TeamInput'))]
    #[OA\Response(
        response: 200,
        description: 'Team was successfully updated',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseTeamDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Team not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function update(int $id, Request $request): JsonResponse
    {
        $team = $this->teamRepository->find($id);
        if (!$team) {
            return $this->notFoundResponse('Team not found');
        }

        $payload = $this->getPayload($request);
        $this->mapPayloadToTeam($team, $payload);

        if ($validationError = $this->validateEntity($team)) {
            return $validationError;
        }

        if (array_key_exists('memberIds', $payload) && is_array($payload['memberIds'])) {
            $this->syncMembers($team, $payload['memberIds']);
        }

        $this->em->flush();

        return $this->successResponse(
            $team->toArray(true),
            'Team was successfully updated'
        );
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/teams/{id}',
        summary: 'Delete team',
        description: 'Removes a team from the system and unlinks members and project associations'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Team ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Team was successfully deleted',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseSuccessMessage')
    )]
    #[OA\Response(
        response: 404,
        description: 'Team not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function delete(int $id): JsonResponse
    {
        $team = $this->teamRepository->find($id);
        if (!$team) {
            return $this->notFoundResponse('Team not found');
        }

        // Unlink members
        foreach ($team->getMembers() as $member) {
            $member->setTeam(null);
        }

        // Unlink from projects
        foreach ($team->getProjects() as $project) {
            $project->removeTeam($team);
        }

        $this->em->remove($team);
        $this->em->flush();

        return $this->successResponse(null, 'Team was successfully deleted');
    }

    private function mapPayloadToTeam(Team $team, array $payload): void
    {
        if (isset($payload['name'])) {
            $team->setName((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $team->setDescription($payload['description']);
        }
    }

    private function syncMembers(Team $team, array $memberIds): void
    {
        foreach ($team->getMembers()->toArray() as $existingMember) {
            if (!in_array($existingMember->getId(), $memberIds, true)) {
                $team->removeMember($existingMember);
            }
        }
        foreach ($memberIds as $memberId) {
            $person = $this->personRepository->find($memberId);
            if ($person) {
                $team->addMember($person);
            }
        }
    }
}
