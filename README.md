Below are the steps to run mediawiki container where docker is required.

Please clone this repositry from below URL
git clone https://github.com/himanshusangal3/mediawiki_docker.git

Then go into the directory
cd mediawiki_docker/

Run below command to build the image
docker build -t mediawiki:1.0 .

Check docker image created with below command
docker images


Run the below command to run the container of mediawiki
docker run --name mediawiki -p 80:8080 -d mediawiki:1.0

Check the running container with below command
docker ps

Now MediaWiki Website is up.
Access with the URL http://ec2-54-87-56-167.compute-1.amazonaws.com/

