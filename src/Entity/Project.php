<?php

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
#[ORM\HasLifecycleCallbacks]
class Project
{
    public const STATUS_PLANNED = 'Planned';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_ON_HOLD = 'On Hold';

    public const ALLOWED_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_ON_HOLD,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['project:read', 'person:read', 'team:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Project name is required')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['project:read', 'project:write', 'person:read', 'team:read'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['project:read', 'project:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Project status is required')]
    #[Assert\Choice(choices: Project::ALLOWED_STATUSES, message: 'Invalid project status')]
    #[Groups(['project:read', 'project:write', 'person:read', 'team:read'])]
    private ?string $status = self::STATUS_PLANNED;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['project:read', 'project:write', 'person:read', 'team:read'])]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['project:read', 'project:write', 'person:read', 'team:read'])]
    private ?\DateTimeInterface $endDate = null;

    /**
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class, inversedBy: 'projects')]
    #[ORM\JoinTable(name: 'project_person')]
    #[Groups(['project:read'])]
    private Collection $persons;

    /**
     * @var Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class, inversedBy: 'projects')]
    #[ORM\JoinTable(name: 'project_team')]
    #[Groups(['project:read'])]
    private Collection $teams;

    #[ORM\Column]
    #[Groups(['project:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->persons = new ArrayCollection();
        $this->teams = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PLANNED;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description ? trim($description) : null;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = trim($status);
        return $this;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    private ?\DateTimeInterface $originalStartDate = null;

    #[ORM\PostLoad]
    public function onPostLoad(): void
    {
        $this->originalStartDate = $this->startDate ? clone $this->startDate : null;
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        $todayStr = (new \DateTime())->format('Y-m-d');
        $startStr = $this->startDate?->format('Y-m-d');
        $endStr = $this->endDate?->format('Y-m-d');

        $isNewStartDate = $this->originalStartDate === null
            || ($startStr !== null && $startStr !== $this->originalStartDate->format('Y-m-d'));

        // Start date cannot be in the past for new projects or when modifying start date
        if ($startStr !== null && $isNewStartDate && $startStr < $todayStr) {
            $context->buildViolation('Start date cannot be in the past')
                ->atPath('startDate')
                ->addViolation();
        }

        // End date cannot be before start date
        if ($startStr !== null && $endStr !== null && $endStr < $startStr) {
            $context->buildViolation('End date cannot be earlier than start date')
                ->atPath('endDate')
                ->addViolation();
        }
    }

    /**
     * @return Collection<int, Person>
     */
    public function getPersons(): Collection
    {
        return $this->persons;
    }

    public function addPerson(Person $person): static
    {
        if (!$this->persons->contains($person)) {
            $this->persons->add($person);
        }
        return $this;
    }

    public function removePerson(Person $person): static
    {
        $this->persons->removeElement($person);
        return $this;
    }

    /**
     * @return Collection<int, Team>
     */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }
        return $this;
    }

    public function removeTeam(Team $team): static
    {
        $this->teams->removeElement($team);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns all unique participants (direct assignees + members of assigned teams)
     * @return array<int, array>
     */
    public function getAllParticipants(): array
    {
        $participants = [];

        // Directly assigned persons
        foreach ($this->persons as $person) {
            $participants[$person->getId()] = [
                'id' => $person->getId(),
                'name' => $person->getName(),
                'email' => $person->getEmail(),
                'role' => $person->getRole(),
                'assignmentType' => 'direct',
                'team' => $person->getTeam() ? [
                    'id' => $person->getTeam()->getId(),
                    'name' => $person->getTeam()->getName(),
                ] : null,
            ];
        }

        // Team members
        foreach ($this->teams as $team) {
            foreach ($team->getMembers() as $member) {
                if (isset($participants[$member->getId()])) {
                    $participants[$member->getId()]['assignmentType'] = 'both';
                    $participants[$member->getId()]['viaTeam'] = [
                        'id' => $team->getId(),
                        'name' => $team->getName(),
                    ];
                } else {
                    $participants[$member->getId()] = [
                        'id' => $member->getId(),
                        'name' => $member->getName(),
                        'email' => $member->getEmail(),
                        'role' => $member->getRole(),
                        'assignmentType' => 'team',
                        'team' => [
                            'id' => $team->getId(),
                            'name' => $team->getName(),
                        ],
                        'viaTeam' => [
                            'id' => $team->getId(),
                            'name' => $team->getName(),
                        ],
                    ];
                }
            }
        }

        return array_values($participants);
    }

    public function toArray(bool $includeRelations = true): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'startDate' => $this->startDate?->format('Y-m-d'),
            'endDate' => $this->endDate?->format('Y-m-d'),
            'directPersonsCount' => $this->persons->count(),
            'teamsCount' => $this->teams->count(),
            'totalParticipantsCount' => count($this->getAllParticipants()),
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];

        if ($includeRelations) {
            $data['persons'] = array_values($this->persons->map(fn(Person $p) => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'email' => $p->getEmail(),
                'role' => $p->getRole(),
                'team' => $p->getTeam() ? [
                    'id' => $p->getTeam()->getId(),
                    'name' => $p->getTeam()->getName(),
                ] : null,
            ])->toArray());

            $data['teams'] = array_values($this->teams->map(fn(Team $t) => [
                'id' => $t->getId(),
                'name' => $t->getName(),
                'membersCount' => $t->getMembers()->count(),
            ])->toArray());

            $data['allParticipants'] = $this->getAllParticipants();
        }

        return $data;
    }
}
