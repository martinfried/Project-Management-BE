<?php

namespace App\Entity;

use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'persons')]
#[ORM\HasLifecycleCallbacks]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['person:read', 'project:read', 'team:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Name is required')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['person:read', 'person:write', 'project:read', 'team:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email format')]
    #[Groups(['person:read', 'person:write', 'project:read', 'team:read'])]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Role is required')]
    #[Groups(['person:read', 'person:write', 'project:read', 'team:read'])]
    private ?string $role = null;

    #[ORM\ManyToOne(targetEntity: Team::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['person:read', 'person:write'])]
    private ?Team $team = null;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\ManyToMany(targetEntity: Project::class, mappedBy: 'persons')]
    #[Groups(['person:read'])]
    private Collection $projects;

    #[ORM\Column]
    #[Groups(['person:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = trim(strtolower($email));
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = trim($role);
        return $this;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): static
    {
        if ($this->team === $team) {
            return $this;
        }

        $oldTeam = $this->team;
        $this->team = $team;

        if ($oldTeam !== null && $oldTeam->getMembers()->contains($this)) {
            $oldTeam->getMembers()->removeElement($this);
        }

        if ($team !== null && !$team->getMembers()->contains($this)) {
            $team->getMembers()->add($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Project>
     */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): static
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->addPerson($this);
        }
        return $this;
    }

    public function removeProject(Project $project): static
    {
        if ($this->projects->removeElement($project)) {
            $project->removePerson($this);
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(bool $includeRelations = true): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'team' => $this->team ? [
                'id' => $this->team->getId(),
                'name' => $this->team->getName(),
            ] : null,
            'projectsCount' => $this->projects->count(),
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];

        if ($includeRelations) {
            $data['projects'] = array_values($this->projects->map(fn(Project $p) => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'status' => $p->getStatus(),
                'startDate' => $p->getStartDate()?->format('Y-m-d'),
                'endDate' => $p->getEndDate()?->format('Y-m-d'),
            ])->toArray());
        }

        return $data;
    }
}
