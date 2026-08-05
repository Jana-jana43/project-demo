pipeline {
    agent any

    environment {
        DEPLOY_USER = "ubuntu"
        DEPLOY_HOST = "172.31.3.202"
        DEPLOY_PATH = "/var/www/project.com"
        SSH_KEY = "/var/lib/jenkins/.ssh/deploy_ed25519"

        DOCKER_IMAGE = "project-image"
        DOCKER_CONTAINER = "project-docker-test"
        DOCKER_PORT = "8081"
    }

    stages {

        stage('Checkout') {
            steps {
                echo "Checking out latest code from Git..."

                checkout scm
            }
        }

        stage('Deploy Files to Production') {
            steps {
                echo "Copying latest files to production server..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '
                            mkdir -p ${DEPLOY_PATH}
                        '

                    rsync -avz --delete \
                        -e "ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no" \
                        ./ \
                        ${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PATH}/
                """
            }
        }

        stage('Set Permissions') {
            steps {
                echo "Setting production file permissions..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '
                            sudo chown -R ubuntu:www-data ${DEPLOY_PATH}
                            sudo find ${DEPLOY_PATH} -type d -exec chmod 775 {} \\;
                            sudo find ${DEPLOY_PATH} -type f -exec chmod 664 {} \\;
                        '
                """
            }
        }

        stage('Build Docker Image') {
            steps {
                echo "Building Docker image..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '
                            cd ${DEPLOY_PATH}

                            docker build \
                                -t ${DOCKER_IMAGE}:latest \
                                .
                        '
                """
            }
        }

        stage('Deploy Docker Container') {
            steps {
                echo "Deploying new Docker container..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '

                            echo "Removing old container..."

                            docker rm -f ${DOCKER_CONTAINER} || true

                            echo "Starting new container..."

                            docker run -d \
                                --name ${DOCKER_CONTAINER} \
                                --restart unless-stopped \
                                -p ${DOCKER_PORT}:80 \
                                ${DOCKER_IMAGE}:latest

                            echo "Container started."
                        '
                """
            }
        }

        stage('Health Check') {
            steps {
                echo "Running application health check..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '

                            echo "Waiting for container..."
                            sleep 5

                            echo "Checking container status..."
                            docker ps --filter "name=${DOCKER_CONTAINER}"

                            echo "Checking website..."

                            curl --fail \
                                --silent \
                                --show-error \
                                http://localhost:${DOCKER_PORT} \
                                > /dev/null

                            echo "Docker website is healthy!"
                        '
                """
            }
        }

        stage('Reload Apache') {
            steps {
                echo "Reloading Apache..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '
                            sudo systemctl reload apache2
                        '
                """
            }
        }

        stage('Deployment Verification') {
            steps {
                echo "Final deployment verification..."

                sh """
                    ssh -i ${SSH_KEY} \
                        -o StrictHostKeyChecking=no \
                        ${DEPLOY_USER}@${DEPLOY_HOST} '

                            echo "Docker container:"
                            docker ps --filter "name=${DOCKER_CONTAINER}"

                            echo "Docker image:"
                            docker images ${DOCKER_IMAGE}

                            echo "HTTP Response:"
                            curl -I http://localhost:${DOCKER_PORT}
                        '
                """
            }
        }
    }

    post {

        success {
            echo "=========================================="
            echo "Deployment completed successfully!"
            echo "Docker container is running."
            echo "Website health check passed."
            echo "=========================================="
        }

        failure {
            echo "=========================================="
            echo "Deployment failed!"
            echo "Check Jenkins Console Output."
            echo "=========================================="
        }

        always {
            echo "Pipeline execution completed."
        }
    }
}
