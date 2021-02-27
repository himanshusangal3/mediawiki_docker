# MediaWiki Dockerfile

###Clone this repositry from below URL
```
$ git clone https://github.com/himanshusangal3/mediawiki_docker.git`
```

###Then go into the directory
```
$ cd mariadb_docker/
```

### Build the image

To create the image `mediawiki`, execute the following command on the `docker-mariadb` folder:

```
$ docker build -t mediawiki:1.0 .
```


### Check the created docker images
```
$ docker images
```
### Run the image

To run the image and bind to host port 3306:

```
$ docker run --name mediawiki -p 80:8080 -d mediawiki:1.
```

###Check the running container with  command
```
docker ps
```

###Now MediaWiki Website is up.
`Access with the URL http://<server-name>/`

