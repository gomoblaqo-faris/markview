# Diagram Examples

This page demonstrates Mermaid diagram rendering in MarkView. Create diagrams using simple text-based syntax!

## Flowchart

```mermaid
graph TD
    A[Start] --> B{Is it working?}
    B -->|Yes| C[Great!]
    B -->|No| D[Debug]
    D --> E[Fix Issues]
    E --> B
    C --> F[End]
```

## Sequence Diagram

```mermaid
sequenceDiagram
    participant User
    participant Browser
    participant Server
    participant Database

    User->>Browser: Enter URL
    Browser->>Server: HTTP Request
    Server->>Database: Query Data
    Database-->>Server: Return Results
    Server-->>Browser: HTTP Response
    Browser-->>User: Display Page
```

## Class Diagram

```mermaid
classDiagram
    class User {
        +String name
        +String email
        +String password
        +login()
        +logout()
        +updateProfile()
    }

    class Post {
        +String title
        +String content
        +Date createdAt
        +publish()
        +delete()
    }

    class Comment {
        +String text
        +Date createdAt
        +edit()
        +delete()
    }

    User "1" --> "*" Post : creates
    Post "1" --> "*" Comment : has
    User "1" --> "*" Comment : writes
```

## State Diagram

```mermaid
stateDiagram-v2
    [*] --> Idle
    Idle --> Processing: Start
    Processing --> Success: Complete
    Processing --> Error: Fail
    Success --> [*]
    Error --> Retry: Retry
    Retry --> Processing
    Error --> [*]: Give Up
```

## Entity Relationship Diagram

```mermaid
erDiagram
    USER ||--o{ ORDER : places
    USER {
        int id
        string name
        string email
    }
    ORDER ||--|{ ORDER_ITEM : contains
    ORDER {
        int id
        date order_date
        string status
    }
    PRODUCT ||--o{ ORDER_ITEM : "ordered in"
    PRODUCT {
        int id
        string name
        decimal price
    }
    ORDER_ITEM {
        int quantity
        decimal unit_price
    }
```

## Gantt Chart

```mermaid
gantt
    title Project Development Timeline
    dateFormat  YYYY-MM-DD
    section Planning
    Requirements Analysis    :a1, 2024-01-01, 10d
    Design Mockups          :a2, after a1, 7d
    section Development
    Backend API             :b1, after a2, 20d
    Frontend UI             :b2, after a2, 25d
    Integration             :b3, after b1, 10d
    section Testing
    Unit Tests              :c1, after b2, 5d
    Integration Tests       :c2, after b3, 7d
    UAT                     :c3, after c2, 5d
    section Deployment
    Production Deploy       :d1, after c3, 2d
```

## Pie Chart

```mermaid
pie title Programming Language Usage
    "JavaScript" : 35
    "PHP" : 25
    "Python" : 20
    "Java" : 12
    "Other" : 8
```

## Git Graph

```mermaid
gitGraph
    commit id: "Initial commit"
    commit id: "Add features"
    branch develop
    checkout develop
    commit id: "Dev changes"
    checkout main
    merge develop
    commit id: "Release v1.0"
    branch feature
    checkout feature
    commit id: "New feature"
    checkout main
    merge feature
    commit id: "Release v2.0"
```

## User Journey

```mermaid
journey
    title User Experience with MarkView
    section Discovery
      Find MarkView: 5: User
      Read README: 4: User
    section Setup
      Download Files: 5: User
      Start PHP Server: 4: User
    section Usage
      Browse Files: 5: User
      View Markdown: 5: User
      View Diagrams: 5: User
      Share with Team: 5: User
```

## Mindmap

```mermaid
mindmap
  root((MarkView))
    Features
      File Browser
      Syntax Highlighting
      Diagrams
      Tables
    Technology
      PHP
      Tailwind CSS
      Mermaid.js
      Highlight.js
    Benefits
      Zero Dependencies
      Single File
      Portable
      Secure
```

## Timeline

```mermaid
timeline
    title MarkView Development History
    2024-10 : Created initial version
            : Added file browser
            : Implemented Tailwind CSS
    2024-10 : Added table support
            : Implemented symlink support
            : Added syntax highlighting
    2024-10 : Added Mermaid diagrams
            : Complete feature set
```

## How to Use

Simply create a code block with `mermaid` as the language:

\`\`\`mermaid
graph LR
    A[Your] --> B[Diagram]
    B --> C[Here]
\`\`\`

[Back to README](README.md) | [See Code Examples](code-examples.md)
