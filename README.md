# aws-ec2-backup

This is a backup script for EC2 instances. It

* create snapshot,
* and delete old snapshots.

Running it (*remember to install composer packages first*)

```bash
php index.php
```

# Setup Instruction

Rename *config.php.example* to *config.php*. Set expiry time (in seconds) for snapshot. Snapshots older than this time will be deleted. The default is 604800s (7 days). If all snapshots have expired, the latest copy will be preserved.

Log can be send to list of email address specified in `log.recipients` in config file. If not email address is specified, log will be sent to STDOUT.

Rename *credentials.php.example* to *credentials.php*. Create an entry for each [volume](http://docs.aws.amazon.com/AWSEC2/latest/UserGuide/EBSVolumes.html) you want to create snapshot for. In each entry, define

* A name for each entry. This name will be used to name the created snapshot.
* `key` - Access key ID.
* `secret` - Access Key secret.
* `volumeId` - Identifer of volume.
* `region` - Reference: http://docs.aws.amazon.com/general/latest/gr/rande.html#apigateway_region

Hints

* Follow this instruction to create access key, http://aws.amazon.com/developers/access-keys/
* I recommend creating an permission-restricted [IAM user](http://docs.aws.amazon.com/lambda/latest/dg/setting-up.html) for generating access key, instead of using personal AWS login accounts.
* Same access key ID/secret pair can be shared among different volumes.

Recommended policy for the IAM user. Reference: http://amzn.to/29TblQQ

```javascript
{
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "ec2:DeleteSnapshot",
                "ec2:CreateSnapshot",
                "ec2:DescribeSnapshots",
                "ec2:CreateTags"
            ],
            "Resource": "*"
        }
    ]
}
```
