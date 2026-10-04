# Agent Guidelines & Commit Message Convention

All git commit messages in this repository must strictly adhere to the **Conventional Commits** format:

```
<type>(<scope>): <short summary in imperative mood>

[optional body explaining context, rationale, and breaking changes]
```

## Allowed Types

- `feat`: A new feature or endpoint
- `fix`: A bug fix
- `refactor`: Code refactoring that neither fixes a bug nor adds a feature
- `chore`: Tooling, build config, Docker, dependencies, or repository maintenance
- `docs`: Documentation changes
- `style`: Formatting, whitespace, or lint fixes
- `test`: Adding or updating tests

## Common Scopes

- `setup`: Project initialization and structure
- `docker`: Dockerfile, compose, and container entrypoints
- `entity`: Doctrine entities and ORM mappings
- `repository`: Doctrine repository classes
- `controller`: API controllers and endpoints
- `command`: Console CLI commands
- `api`: OpenAPI specification, routing, and Swagger configuration
- `config`: Symfony configuration files

## Rules

1. **Imperative Mood**: Use imperative present tense ("add", "update", "fix", not "added", "updated", "fixes").
2. **Case**: Lowercase type, scope, and first word of the subject (e.g. `feat(project): add team assignment endpoint`).
3. **No Trailing Period**: Do not end the subject line with a period.
4. **Length**: Keep the subject line under 72 characters.
5. **Atomic Commits**: Each commit must represent a single logical change or responsibility. Never batch unrelated files or an entire project into a single generic commit.
