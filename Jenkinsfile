pipeline {
    agent any

    environment {
        DEPLOY_USER = "ubuntu"
        DEPLOY_HOST = "172.31.3.202"      // Production Private IP
        DEPLOY_PATH = "/var/www/project.com"
        SSH_KEY = "/var/lib/jenkins/.ssh/deploy_ed25519"
    }

    stages {

        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Deploy to Production') {
            steps {
                sh """
                ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no ${DEPLOY_USER}@${DEPLOY_HOST} '
                    mkdir -p ${DEPLOY_PATH}
                '

                rsync -avz --delete \
                    -e "ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no" \
                    ./ ${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PATH}/
                """
            }
        }

        stage('Set Permissions') {
            steps {
                sh """
                ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no ${DEPLOY_USER}@${DEPLOY_HOST} '
                    sudo chown -R ubuntu:www-data ${DEPLOY_PATH}
                    sudo find ${DEPLOY_PATH} -type d -exec chmod 775 {} \\;
                    sudo find ${DEPLOY_PATH} -type f -exec chmod 664 {} \\;
                '
                """
            }
        }

        stage('Reload Apache') {
            steps {
                sh """
                ssh -i ${SSH_KEY} -o StrictHostKeyChecking=no ${DEPLOY_USER}@${DEPLOY_HOST} '
                    sudo systemctl reload apache2
                '
                """
            }
        }
    }

    post {
        success {
            echo "✅ Deployment completed successfully!"
        }
        failure {
            echo "❌ Deployment failed!"
        }
    }
}
