<?php

namespace App\Command;

use App\Entity\Person;
use App\Entity\Project;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:init-db',
    description: 'Initializes the database schema and optionally seeds realistic sample data'
)]
class InitDatabaseCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('seed', null, InputOption::VALUE_NONE, 'Seed sample data after schema creation');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force replace existing data with fresh sample data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Database Initialization');

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);

        $schemaTool->updateSchema($metadata);
        $io->success('Database schema has been created / updated.');

        if ($input->getOption('seed')) {
            $force = (bool) $input->getOption('force');
            $this->seedSampleData($force);
            $io->success('Sample data has been seeded successfully.');
        }

        return Command::SUCCESS;
    }

    public function seedSampleData(bool $force = false): void
    {
        if ($force) {
            $conn = $this->em->getConnection();
            try {
                $conn->executeStatement('DELETE FROM project_person');
                $conn->executeStatement('DELETE FROM project_team');
                $conn->executeStatement('DELETE FROM projects');
                $conn->executeStatement('DELETE FROM persons');
                $conn->executeStatement('DELETE FROM teams');
                $conn->executeStatement("DELETE FROM sqlite_sequence WHERE name IN ('projects', 'persons', 'teams')");
            } catch (\Throwable) {
                // In case tables or sqlite_sequence do not exist yet
            }
        } else {
            $existingProjects = $this->em->getRepository(Project::class)->findAll();
            if (count($existingProjects) > 0) {
                return;
            }
        }

        // 1. Create Teams
        $teamCore = (new Team())
            ->setName('Core Platform Team')
            ->setDescription('Responsible for core architecture, cloud infrastructure, and data persistence layers.');

        $teamFrontend = (new Team())
            ->setName('Frontend & UX Team')
            ->setDescription('Responsible for user interface development, design system, and client-side application logic.');

        $teamSecurity = (new Team())
            ->setName('Security & QA Team')
            ->setDescription('Responsible for security audits, penetration testing, and automated test pipelines.');

        $this->em->persist($teamCore);
        $this->em->persist($teamFrontend);
        $this->em->persist($teamSecurity);

        // 2. Create Persons
        $person1 = (new Person())
            ->setName('John Smith')
            ->setEmail('john.smith@example.com')
            ->setRole('Project Lead')
            ->setTeam($teamCore);

        $person2 = (new Person())
            ->setName('Emily Brown')
            ->setEmail('emily.brown@example.com')
            ->setRole('Systems Analyst')
            ->setTeam($teamCore);

        $person3 = (new Person())
            ->setName('Michael Davis')
            ->setEmail('michael.davis@example.com')
            ->setRole('Lead Developer')
            ->setTeam($teamFrontend);

        $person4 = (new Person())
            ->setName('Sarah Wilson')
            ->setEmail('sarah.wilson@example.com')
            ->setRole('Frontend Developer')
            ->setTeam($teamFrontend);

        $person5 = (new Person())
            ->setName('David Clark')
            ->setEmail('david.clark@example.com')
            ->setRole('Cloud Architect')
            ->setTeam($teamCore);

        $person6 = (new Person())
            ->setName('Jessica Taylor')
            ->setEmail('jessica.taylor@example.com')
            ->setRole('QA & Security Tester')
            ->setTeam($teamSecurity);

        $this->em->persist($person1);
        $this->em->persist($person2);
        $this->em->persist($person3);
        $this->em->persist($person4);
        $this->em->persist($person5);
        $this->em->persist($person6);

        // 3. Create Projects
        $proj1 = (new Project())
            ->setName('Information Portal & Data Warehouse')
            ->setDescription('Comprehensive modernization of internal project management, analytics reporting, and external data integrations.')
            ->setStatus(Project::STATUS_IN_PROGRESS)
            ->setStartDate(new \DateTime('2026-01-15'))
            ->setEndDate(new \DateTime('2026-12-31'));
        $proj1->addPerson($person1);
        $proj1->addPerson($person2);
        $proj1->addTeam($teamFrontend);
        $proj1->addTeam($teamCore);

        $proj2 = (new Project())
            ->setName('Security Audit & Infrastructure Hardening')
            ->setDescription('Regular review of access permissions, vulnerability assessment, and implementation of zero-trust perimeter.')
            ->setStatus(Project::STATUS_PLANNED)
            ->setStartDate(new \DateTime('2026-04-01'))
            ->setEndDate(new \DateTime('2026-06-30'));
        $proj2->addPerson($person5);
        $proj2->addTeam($teamSecurity);

        $proj3 = (new Project())
            ->setName('Mobile Operations Application')
            ->setDescription('Responsive PWA application and mobile interface for field operations and real-time synchronization.')
            ->setStatus(Project::STATUS_COMPLETED)
            ->setStartDate(new \DateTime('2025-08-01'))
            ->setEndDate(new \DateTime('2026-01-30'));
        $proj3->addPerson($person3);
        $proj3->addPerson($person4);

        $this->em->persist($proj1);
        $this->em->persist($proj2);
        $this->em->persist($proj3);

        $this->em->flush();
    }
}
