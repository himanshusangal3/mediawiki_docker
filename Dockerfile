FROM registry.access.redhat.com/ubi8/ubi:8.1

RUN yum --disableplugin=subscription-manager -y module enable php:7.3 \
  && yum --disableplugin=subscription-manager -y install httpd php \
  && dnf install -y php-mysqlnd php-gd php-xml php-mbstring php-json \
  && yum --disableplugin=subscription-manager clean all


COPY mediawiki /var/www/mediawiki

RUN sed -i 's/\/var\/www\/html/\/var\/www\/mediawiki/g' /etc/httpd/conf/httpd.conf \
  && chown -R apache:apache /var/www/mediawiki \
  && chown -R apache:apache /var/lib \
  && chmod 777 /var/lib/php/session \
  && sed -i 's/Listen 80/Listen 8080/' /etc/httpd/conf/httpd.conf \
  && sed -i 's/listen.acl_users = apache,nginx/listen.acl_users =/' /etc/php-fpm.d/www.conf \
  && mkdir /run/php-fpm \
  && chgrp -R 0 /var/log/httpd /var/run/httpd /run/php-fpm \
  && chmod -R g=u /var/log/httpd /var/run/httpd /run/php-fpm

EXPOSE 8080
USER 1001
CMD php-fpm & httpd -D FOREGROUND
