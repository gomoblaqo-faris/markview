# Syntax Highlighting Test

This demonstrates proper syntax highlighting for different programming languages.

## PHP Code

```php
<?php
class DatabaseConnection {
    private $host = 'localhost';
    private $username = 'admin';
    private $password = 'secret';

    public function connect() {
        try {
            $pdo = new PDO("mysql:host={$this->host}", $this->username, $this->password);
            return $pdo;
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public function query($sql, $params = []) {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$db = new DatabaseConnection();
$users = $db->query("SELECT * FROM users WHERE active = ?", [1]);
?>
```

## JavaScript Code

```javascript
// Modern JavaScript with ES6+ features
class UserManager {
    constructor(apiUrl) {
        this.apiUrl = apiUrl;
        this.users = [];
    }

    async fetchUsers() {
        try {
            const response = await fetch(`${this.apiUrl}/users`);
            const data = await response.json();
            this.users = data.map(user => ({
                id: user.id,
                name: user.name,
                email: user.email,
                isActive: user.active
            }));
            return this.users;
        } catch (error) {
            console.error('Failed to fetch users:', error);
            throw new Error('User fetch failed');
        }
    }

    findUser(id) {
        return this.users.find(user => user.id === id) || null;
    }
}

const manager = new UserManager('https://api.example.com');
manager.fetchUsers().then(users => {
    console.log('Loaded users:', users.length);
});
```

## Bash/Shell Script

```bash
#!/bin/bash

# Script to deploy application
APP_NAME="markview"
DEPLOY_DIR="/var/www/html"
BACKUP_DIR="/backups"
DATE=$(date +%Y%m%d_%H%M%S)

echo "Starting deployment of $APP_NAME..."

# Create backup
if [ -d "$DEPLOY_DIR/$APP_NAME" ]; then
    echo "Creating backup..."
    tar -czf "$BACKUP_DIR/${APP_NAME}_${DATE}.tar.gz" "$DEPLOY_DIR/$APP_NAME"

    if [ $? -eq 0 ]; then
        echo "Backup created successfully"
    else
        echo "Backup failed!"
        exit 1
    fi
fi

# Deploy new version
echo "Deploying new version..."
cp -r ./* "$DEPLOY_DIR/$APP_NAME/"

# Set permissions
chmod -R 755 "$DEPLOY_DIR/$APP_NAME"
chown -R www-data:www-data "$DEPLOY_DIR/$APP_NAME"

# Restart services
systemctl restart php-fpm
systemctl restart nginx

echo "Deployment completed successfully!"
```

## Python Code

```python
import requests
from typing import List, Dict, Optional
from datetime import datetime

class APIClient:
    """A simple API client for making HTTP requests"""

    def __init__(self, base_url: str, api_key: str):
        self.base_url = base_url
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({
            'Authorization': f'Bearer {api_key}',
            'Content-Type': 'application/json'
        })

    def get_users(self, active_only: bool = True) -> List[Dict]:
        """Fetch users from the API"""
        params = {'active': 1} if active_only else {}
        response = self.session.get(
            f'{self.base_url}/users',
            params=params
        )
        response.raise_for_status()
        return response.json()

    def create_user(self, name: str, email: str) -> Optional[Dict]:
        """Create a new user"""
        data = {
            'name': name,
            'email': email,
            'created_at': datetime.now().isoformat()
        }
        try:
            response = self.session.post(
                f'{self.base_url}/users',
                json=data
            )
            response.raise_for_status()
            return response.json()
        except requests.RequestException as e:
            print(f"Error creating user: {e}")
            return None

# Usage
client = APIClient('https://api.example.com', 'your-api-key-here')
users = client.get_users()
print(f"Found {len(users)} active users")
```

## Key Differences

Each language should have distinct color coding:

- **PHP**: Purple `<?php`, orange strings, blue keywords (`class`, `public`, `try`)
- **JavaScript**: Yellow functions, green strings, purple keywords (`const`, `async`, `await`)
- **Bash**: Green strings, gray comments, orange variables (`$APP_NAME`)
- **Python**: Blue keywords (`class`, `def`, `import`), green strings, purple decorators

[Back to README](README.md)
