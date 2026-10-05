<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Person;
use App\Entity\Team;
use App\Repository\ProjectRepository;
use App\Repository\PersonRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/projects')]
#[OA\Tag(name: 'Projects', description: 'Management of projects and person/team assignments')]
class ProjectController extends BaseApiController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private PersonRepository $personRepository,
        private TeamRepository $teamRepository,
        ValidatorInterface $validator
    ) {
        parent::__construct($validator);
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/projects',
        summary: 'List projects',
        description: 'Returns a list of projects with optional search in name/description and status filtering'
    )]
    #[OA\Parameter(name: 'search', in: 'query', description: 'Search query in project name or description', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'status', in: 'query', description: 'Filter by project status', schema: new OA\Schema(ref: '#/components/schemas/ProjectStatus'))]
    #[OA\Response(
        response: 200,
        description: 'List of projects',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectList')
    )]
    public function index(Request $request): JsonResponse
    {
        $search = $request->query->get('search');
        $status = $request->query->get('status');

        $qb = $this->projectRepository->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.description) LIKE :search')
                ->setParameter('search', '%' . strtolower(trim($search)) . '%');
        }

        if ($status && in_array($status, Project::ALLOWED_STATUSES, true)) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', $status);
        }

        $projects = $qb->getQuery()->getResult();
        $data = array_map(fn(Project $project) => $project->toArray(false), $projects);

        return $this->listResponse($data);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/projects/{id}',
        summary: 'Get project details',
        description: 'Returns project details including directly assigned persons, teams, and all aggregated participants'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Project details',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function show(int $id): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            return $this->notFoundResponse('Project not found');
        }

        return $this->successResponse($project->toArray(true));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        path: '/api/projects',
        summary: 'Create project',
        description: 'Creates a new project and optionally assigns initial persons and teams'
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectInput'))]
    #[OA\Response(
        response: 201,
        description: 'Project was successfully created',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 400,
        description: 'Validation error',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->getPayload($request);

        $project = new Project();
        $this->mapPayloadToProject($project, $payload);

        if ($validationError = $this->validateEntity($project)) {
            return $validationError;
        }

        if (isset($payload['personIds']) && is_array($payload['personIds'])) {
            $this->syncPersons($project, $payload['personIds']);
        }

        if (isset($payload['teamIds']) && is_array($payload['teamIds'])) {
            $this->syncTeams($project, $payload['teamIds']);
        }

        $this->em->persist($project);
        $this->em->flush();

        return $this->successResponse(
            $project->toArray(true),
            'Project was successfully created',
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/projects/{id}',
        summary: 'Update project',
        description: 'Updates an existing project and its person/team assignments'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectInput'))]
    #[OA\Response(
        response: 200,
        description: 'Project was successfully updated',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function update(int $id, Request $request): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            return $this->notFoundResponse('Project not found');
        }

        $payload = $this->getPayload($request);
        $this->mapPayloadToProject($project, $payload);

        if ($validationError = $this->validateEntity($project)) {
            return $validationError;
        }

        if (array_key_exists('personIds', $payload) && is_array($payload['personIds'])) {
            $this->syncPersons($project, $payload['personIds']);
        }

        if (array_key_exists('teamIds', $payload) && is_array($payload['teamIds'])) {
            $this->syncTeams($project, $payload['teamIds']);
        }

        $this->em->flush();

        return $this->successResponse(
            $project->toArray(true),
            'Project was successfully updated'
        );
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/projects/{id}',
        summary: 'Delete project',
        description: 'Deletes a project from the database'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Project was successfully deleted',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseSuccessMessage')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function delete(int $id): JsonResponse
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            return $this->notFoundResponse('Project not found');
        }

        // Unlink person relations
        foreach ($project->getPersons() as $person) {
            $person->getProjects()->removeElement($project);
        }
        $project->getPersons()->clear();

        // Unlink team relations
        foreach ($project->getTeams() as $team) {
            $team->getProjects()->removeElement($project);
        }
        $project->getTeams()->clear();

        $this->em->remove($project);
        $this->em->flush();

        return $this->successResponse(null, 'Project was successfully deleted');
    }

    #[Route('/{id}/persons/{personId}', methods: ['POST'])]
    #[OA\Post(
        path: '/api/projects/{id}/persons/{personId}',
        summary: 'Assign person to project',
        description: 'Adds a direct assignment of a person to the project'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'personId', in: 'path', description: 'Person ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Person was assigned to project',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project or person not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function assignPerson(int $id, int $personId): JsonResponse
    {
        $entities = $this->findProjectAndPerson($id, $personId);
        if (!$entities) {
            return $this->notFoundResponse('Project or person not found');
        }

        [$project, $person] = $entities;
        $project->addPerson($person);
        $this->em->flush();

        return $this->successResponse(
            $project->toArray(true),
            'Person was assigned to project'
        );
    }

    #[Route('/{id}/persons/{personId}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/projects/{id}/persons/{personId}',
        summary: 'Remove person from project',
        description: 'Removes direct assignment of a person from the project'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'personId', in: 'path', description: 'Person ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Person was removed from project',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project or person not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function removePerson(int $id, int $personId): JsonResponse
    {
        $entities = $this->findProjectAndPerson($id, $personId);
        if (!$entities) {
            return $this->notFoundResponse('Project or person not found');
        }

        [$project, $person] = $entities;
        $project->removePerson($person);
        $this->em->flush();

        return $this->successResponse(
            $project->toArray(true),
            'Person was removed from project'
        );
    }

    #[Route('/{id}/teams/{teamId}', methods: ['POST'])]
    #[OA\Post(
        path: '/api/projects/{id}/teams/{teamId}',
        summary: 'Assign team to project',
        description: 'Assigns an entire team to the project (all team members become project participants)'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'teamId', in: 'path', description: 'Team ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Team was assigned to project',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project or team not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function assignTeam(int $id, int $teamId): JsonResponse
    {
        $entities = $this->findProjectAndTeam($id, $teamId);
        if (!$entities) {
            return $this->notFoundResponse('Project or team not found');
        }

        [$project, $team] = $entities;
        $project->addTeam($team);
        $this->em->flush();

        return $this->successResponse(
            $project->toArray(true),
            'Team was assigned to project'
        );
    }

    #[Route('/{id}/teams/{teamId}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/projects/{id}/teams/{teamId}',
        summary: 'Remove team from project',
        description: 'Removes team assignment from the project'
    )]
    #[OA\Parameter(name: 'id', in: 'path', description: 'Project ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'teamId', in: 'path', description: 'Team ID', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Team was removed from project',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseProjectDetail')
    )]
    #[OA\Response(
        response: 404,
        description: 'Project or team not found',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
    )]
    public function removeTeam(int $id, int $teamId): JsonResponse
    {
        $entities = $this->findProjectAndTeam($id, $teamId);
        if (!$entities) {
            return $this->notFoundResponse('Project or team not found');
        }

        [$project, $team] = $entities;
        $project->removeTeam($team);
        $this->em->flush();

        return $this->successResponse(
            $project->toArray(true),
            'Team was removed from project'
        );
    }

    private function mapPayloadToProject(Project $project, array $payload): void
    {
        if (isset($payload['name'])) {
            $project->setName((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $project->setDescription($payload['description']);
        }
        if (isset($payload['status'])) {
            $project->setStatus((string) $payload['status']);
        }
        if (array_key_exists('startDate', $payload)) {
            $project->setStartDate(!empty($payload['startDate']) ? new \DateTime($payload['startDate']) : null);
        }
        if (array_key_exists('endDate', $payload)) {
            $project->setEndDate(!empty($payload['endDate']) ? new \DateTime($payload['endDate']) : null);
        }
    }

    private function syncPersons(Project $project, array $personIds): void
    {
        foreach ($project->getPersons()->toArray() as $person) {
            $project->removePerson($person);
        }
        foreach ($personIds as $personId) {
            $person = $this->personRepository->find($personId);
            if ($person) {
                $project->addPerson($person);
            }
        }
    }

    private function syncTeams(Project $project, array $teamIds): void
    {
        foreach ($project->getTeams()->toArray() as $team) {
            $project->removeTeam($team);
        }
        foreach ($teamIds as $teamId) {
            $team = $this->teamRepository->find($teamId);
            if ($team) {
                $project->addTeam($team);
            }
        }
    }

    /**
     * @return array{0: Project, 1: Person}|null
     */
    private function findProjectAndPerson(int $projectId, int $personId): ?array
    {
        $project = $this->projectRepository->find($projectId);
        $person = $this->personRepository->find($personId);

        if (!$project || !$person) {
            return null;
        }

        return [$project, $person];
    }

    /**
     * @return array{0: Project, 1: Team}|null
     */
    private function findProjectAndTeam(int $projectId, int $teamId): ?array
    {
        $project = $this->projectRepository->find($projectId);
        $team = $this->teamRepository->find($teamId);

        if (!$project || !$team) {
            return null;
        }

        return [$project, $team];
    }
}
