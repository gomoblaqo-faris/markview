# Code Highlighting Examples

This page demonstrates syntax highlighting for PHP and JavaScript code in MarkView.

## PHP Examples

### Simple PHP Function

```php
<?php
function greet($name) {
    return "Hello, " . $name . "!";
}

$message = greet("World");
echo $message;
?>
```

### PHP Class Example

```php
<?php
class User {
    private $name;
    private $email;

    public function __construct($name, $email) {
        $this->name = $name;
        $this->email = $email;
    }

    public function getName() {
        return $this->name;
    }

    public function sendEmail($subject, $body) {
        // Send email logic here
        mail($this->email, $subject, $body);
        return true;
    }
}

// Create a new user
$user = new User("John Doe", "john@example.com");
$user->sendEmail("Welcome", "Thanks for joining!");
?>
```

## JavaScript Examples

### Modern JavaScript Function

```javascript
// Arrow function with destructuring
const getUserInfo = ({ name, age, city }) => {
    return `${name} is ${age} years old and lives in ${city}`;
};

const user = {
    name: 'Alice',
    age: 28,
    city: 'New York'
};

console.log(getUserInfo(user));
```

### Async/Await Example

```javascript
async function fetchUserData(userId) {
    try {
        const response = await fetch(`https://api.example.com/users/${userId}`);
        const data = await response.json();

        return {
            success: true,
            user: data
        };
    } catch (error) {
        console.error('Error fetching user:', error);
        return {
            success: false,
            error: error.message
        };
    }
}

// Usage
fetchUserData(123).then(result => {
    if (result.success) {
        console.log('User data:', result.user);
    }
});
```

### React Component Example

```javascript
import React, { useState, useEffect } from 'react';

function Counter() {
    const [count, setCount] = useState(0);
    const [isActive, setIsActive] = useState(false);

    useEffect(() => {
        let interval = null;

        if (isActive) {
            interval = setInterval(() => {
                setCount(count => count + 1);
            }, 1000);
        }

        return () => clearInterval(interval);
    }, [isActive]);

    return (
        <div className="counter">
            <h1>Count: {count}</h1>
            <button onClick={() => setIsActive(!isActive)}>
                {isActive ? 'Pause' : 'Start'}
            </button>
            <button onClick={() => setCount(0)}>Reset</button>
        </div>
    );
}

export default Counter;
```

## Inline Code

You can also use inline code like `$variable = "value";` or `const x = 42;` which appears in red within paragraphs.

## Other Languages

MarkView also supports many other languages through Highlight.js:

### Python

```python
def fibonacci(n):
    if n <= 1:
        return n
    return fibonacci(n-1) + fibonacci(n-2)

print([fibonacci(i) for i in range(10)])
```

### Bash

```bash
#!/bin/bash

# Start PHP server
php -S localhost:8000

# Check if server is running
if [ $? -eq 0 ]; then
    echo "Server started successfully!"
else
    echo "Failed to start server"
    exit 1
fi
```

[Back to README](README.md)
