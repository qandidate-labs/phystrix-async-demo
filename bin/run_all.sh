DIR="$( cd "$( dirname "$0" )" && pwd )"

echo "" > $DIR/../logs/catalogue.log
echo "" > $DIR/../logs/public-shop-api.log
echo "" > $DIR/../logs/commands.log
echo "" > $DIR/../logs/inventory-status.log

php -S 0.0.0.0:8000 -t $DIR/../public-shop-api/ &> $DIR/../logs/public-shop-api.log &
php -S 0.0.0.0:8001 -t $DIR/../catalogue/ &> $DIR/../logs/catalogue.log &
php -S 0.0.0.0:8002 -t $DIR/../inventory-status &> $DIR/../logs/inventory-status.log &

tail -f $DIR/../logs/*.log
