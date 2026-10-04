<?php

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
#[ORM\HasLifecycleCallbacks]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['team:read', 'person:read', 'project:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Team name is required')]
    #[Assert\Length(min: 2, max: 255)]
    #[Groups(['team:read', 'team:write', 'person:read', 'project:read'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['team:read', 'team:write'])]
    private ?string $description = null;

    /**
     * @var Collection<int, Person>
     */
    #[ORM\OneToMany(targetEntity: Person::class, mappedBy: 'team')]
    #[Groups(['team:read'])]
    private Collection $members;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\ManyToMany(targetEntity: Project::class, mappedBy: 'teams')]
    #[Groups(['team:read'])]
    private Collection $projects;

    #[ORM\Column]
    #[Groups(['team:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->members = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description ? trim($description) : null;
        return $this;
    }

    /**
     * @return Collection<int, Person>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Person $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setTeam($this);
        }
        return $this;
    }

    public function removeMember(Person $member): static
    {
        if ($this->members->removeElement($member)) {
            if ($member->getTeam() === $this) {
                $member->setTeam(null);
            }
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
            $project->addTeam($this);
        }
        return $this;
    }

    public function removeProject(Project $project): static
    {
        if ($this->projects->removeElement($project)) {
            $project->removeTeam($this);
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
            'description' => $this->description,
            'membersCount' => $this->members->count(),
            'projectsCount' => $this->projects->count(),
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];

        if ($includeRelations) {
            $data['members'] = $this->members->map(fn(Person $p) => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'email' => $p->getEmail(),
                'role' => $p->getRole(),
            ])->toArray();

            $data['projects'] = $this->projects->map(fn(Project $p) => [
                'id' => $p->getId(),
                'name' => $p->getName(),
                'status' => $p->getStatus(),
            ])->toArray();
        }

        return $data;
    }
}
