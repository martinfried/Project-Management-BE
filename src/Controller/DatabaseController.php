<?php

namespace App\Controller;

use App\Command\InitDatabaseCommand;
use App\Entity\Project;
use App\Entity\Person;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'System', description: 'System health check and database initialization')]
class DatabaseController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private InitDatabaseCommand $initDatabaseCommand
    ) {
    }

    #[Route('/api/health', methods: ['GET'])]
    #[OA\Get(
        path: '/api/health',
        summary: 'API & database health check',
        description: 'Returns the status of database connection and aggregated counts of projects, persons, and teams.'
    )]
    #[OA\Response(
        response: 200,
        description: 'System is healthy',
        content: new OA\JsonContent(ref: '#/components/schemas/HealthStatus')
    )]
    public function health(): JsonResponse
    {
        try {
            $projectCount = $this->em->getRepository(Project::class)->count([]);
            $personCount = $this->em->getRepository(Person::class)->count([]);
            $teamCount = $this->em->getRepository(Team::class)->count([]);

            return $this->json([
                'status' => 'OK',
                'service' => 'Project Management REST API',
                'database' => 'connected',
                'stats' => [
                    'projects' => $projectCount,
                    'persons' => $personCount,
                    'teams' => $teamCount,
                ],
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'DEGRADED',
                'message' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/api/database/init', methods: ['POST'])]
    #[OA\Post(
        path: '/api/database/init',
        summary: 'Initialize database and seed sample data',
        description: 'Creates tables in the SQLite database and seeds default test projects, teams, and persons.'
    )]
    #[OA\Response(
        response: 200,
        description: 'Database initialized successfully',
        content: new OA\JsonContent(ref: '#/components/schemas/ApiResponseSuccessMessage')
    )]
    public function init(): JsonResponse
    {
        try {
            $this->initDatabaseCommand->initializeDatabase(seed: true, force: true);

            return $this->json([
                'success' => true,
                'message' => 'Database was successfully initialized and seeded with sample data.',
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
